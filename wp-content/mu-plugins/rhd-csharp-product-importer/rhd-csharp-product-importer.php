<?php
/**
 * Plugin Name: RHD C. Sharp Product Importer
 * Description: Imports C. Sharp products into WooCommerce. Requires WooCommerce, WooCommerce Product Bundles, and Advanced Custom Fields.
 * Version: 0.1
 * Author: Roundhouse Designs
 * Author URI: https://roundhouse-designs.com
 * Text Domain: rhd
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 5.0
 * Requires PHP: 8.2
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'RHD_CSHARP_IMPORTER_VERSION', '1.0.0' );
define( 'RHD_CSHARP_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'RHD_CSHARP_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

/**
 * Main plugin class
 */
class RHD_CSharp_Product_Importer {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', [$this, 'init'] );
		add_action( 'admin_menu', [$this, 'add_admin_menu'] );
		add_action( 'admin_enqueue_scripts', [$this, 'admin_scripts'] );
		// AJAX handlers
		add_action( 'wp_ajax_rhd_import_products', [$this, 'ajax_import_products'] );
		add_action( 'wp_ajax_rhd_get_csv_info', [$this, 'ajax_get_csv_info'] );
		add_action( 'wp_ajax_rhd_process_chunk', [$this, 'ajax_process_chunk'] );
		add_action( 'wp_ajax_rhd_finalize_import', [$this, 'ajax_finalize_import'] );

		// Check for required plugins
		add_action( 'admin_notices', [$this, 'check_dependencies'] );

		// Add custom product template support
		add_action( 'woocommerce_before_add_to_cart_form', [$this, 'display_custom_bundle_template'] );
		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts'] );

		// Hide default add-to-cart form for bundle products
		add_action( 'woocommerce_single_product_summary', [$this, 'maybe_hide_default_add_to_cart'], 5 );
		add_filter( 'woocommerce_is_purchasable', [$this, 'maybe_hide_purchasable'], 10, 2 );
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Check if WooCommerce is active
		if ( !class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Load text domain
		load_plugin_textdomain( 'rhd', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * Check plugin dependencies
	 */
	public function check_dependencies() {
		$missing_plugins = [];

		if ( !class_exists( 'WooCommerce' ) ) {
			$missing_plugins[] = 'WooCommerce';
		}

		if ( !class_exists( 'WC_Bundles' ) ) {
			$missing_plugins[] = 'WooCommerce Product Bundles';
		}

		if ( !empty( $missing_plugins ) ) {
			echo '<div class="notice notice-error"><p>';
			echo sprintf(
				__( 'RHD C. Sharp Product Importer requires the following plugins to be installed and activated: %s', 'rhd' ),
				implode( ', ', $missing_plugins )
			);
			echo '</p></div>';
		}
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'C. Sharp Product Importer', 'rhd' ),
			__( 'C. Sharp Importer', 'rhd' ),
			'manage_woocommerce',
			'rhd-csharp-importer',
			[$this, 'admin_page']
		);
	}

	/**
	 * Enqueue admin scripts
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_rhd-csharp-importer' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'rhd-csharp-importer-admin',
			RHD_CSHARP_IMPORTER_URL . 'assets/admin.js',
			['jquery'],
			RHD_CSHARP_IMPORTER_VERSION,
			true
		);

		wp_localize_script( 'rhd-csharp-importer-admin', 'rhdImporter', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'rhd_csv_import' ),
			'strings' => [
				'importing' => __( 'Importing products...', 'rhd' ),
				'success'   => __( 'Import completed successfully!', 'rhd' ),
				'error'     => __( 'Import failed. Please try again.', 'rhd' ),
			],
		] );
	}

	/**
	 * Admin page HTML
	 */
	public function admin_page() {
		include plugin_dir_path( __FILE__ ) . 'templates/admin-form.php';
	}

	/**
	 * Handle AJAX import request
	 */
	public function ajax_import_products() {
		// Verify nonce
		if ( !wp_verify_nonce( $_POST['nonce'] ?? '', 'rhd_csv_import' ) ) {
			wp_die( __( 'Security check failed', 'rhd' ) );
		}

		// Check user permissions
		if ( !current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Insufficient permissions', 'rhd' ) );
		}

		$create_bundles  = isset( $_POST['create_bundles'] ) && '1' === $_POST['create_bundles'];
		$update_existing = isset( $_POST['update_existing'] ) && '1' === $_POST['update_existing'];

		// Handle file upload
		if ( empty( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== $_FILES['csv_file']['error'] ) {
			wp_send_json_error( __( 'Please select a valid CSV file', 'rhd' ) );
		}

		$file_path = $_FILES['csv_file']['tmp_name'];

		try {
			$results = $this->import_products_from_csv( $file_path, $update_existing, $create_bundles );

			wp_send_json_success( [
				'message' => sprintf(
					__( 'Import completed successfully. Products imported: %d, Products updated: %d, Bundles created: %d', 'rhd' ),
					$results['products_imported'],
					$results['products_updated'],
					$results['bundles_created']
				),
				'details' => $results,
			] );
		} catch ( Exception $e ) {
			wp_send_json_error( sprintf( __( 'Import failed: %s', 'rhd' ), $e->getMessage() ) );
		}
	}

	/**
	 * Import products from CSV file
	 */
	private function import_products_from_csv( $file_path, $update_existing = false, $create_bundles = false ) {

		$results = [
			'products_imported'        => 0,
			'products_updated'         => 0,
			'bundles_created'          => 0,
			'grouped_products_updated' => 0,
			'errors'                   => [],
		];

		// Read CSV file
		$csv_data = $this->parse_csv( $file_path );

		if ( empty( $csv_data ) ) {
			throw new Exception( __( 'No data found in CSV file', 'rhd' ) );
		}

		$product_families  = [];
		$imported_products = [];

		// First pass: Import individual products (excluding Full Set products)
		$row_index = 0;
		foreach ( $csv_data as $row ) {
			$row_index++;

			try {
				// Skip bundle products (products without SKU)
				if ( empty( $row['Product ID'] ) ) {
					continue;
				}

				// Skip Full Set products in first pass - they'll be handled as bundles
				if ( isset( $row['Single Instrument'] ) && strtolower( trim( $row['Single Instrument'] ) ) === 'full set' ) {
					continue;
				}

				$product_id = $this->import_single_product( $row, $update_existing );

				if ( $product_id ) {
					$imported_products[$row['Product ID']] = $product_id;

					// Group products by family for bundle creation
					$base_sku = $this->get_base_sku( $row['Product ID'] );
					if ( !isset( $product_families[$base_sku] ) ) {
						$product_families[$base_sku] = [
							'products'      => [],
							'full_set_data' => null,
							'base_data'     => $row,
						];
					}
					$product_families[$base_sku]['products'][] = $product_id;
				}

				if ( $update_existing && get_post_meta( $product_id, '_created_via_import', true ) ) {
					$results['products_updated']++;
				} else {
					$results['products_imported']++;
				}
			} catch ( Exception $e ) {
				$results['errors'][] = sprintf(
					__( 'Error importing product %s: %s', 'rhd' ),
					$row['Product ID'] ?? 'Unknown',
					$e->getMessage()
				);
			}
		}

		// Second pass: Process Full Set products and create bundles
		foreach ( $csv_data as $row ) {
			if ( empty( $row['Product ID'] ) ) {
				continue;
			}

			// Only process Full Set products in second pass
			if ( isset( $row['Single Instrument'] ) && strtolower( trim( $row['Single Instrument'] ) ) === 'full set' ) {
				$base_sku = $this->get_base_sku( $row['Product ID'] );

				if ( isset( $product_families[$base_sku] ) ) {
					$product_families[$base_sku]['full_set_data'] = $row;

					try {
						$bundle_id = $this->create_product_bundle( $base_sku, $product_families[$base_sku] );
						if ( $bundle_id ) {
							$results['bundles_created']++;
						}
					} catch ( Exception $e ) {
						$results['errors'][] = sprintf(
							__( 'Error creating bundle for %s: %s', 'rhd' ),
							$base_sku,
							$e->getMessage()
						);
					}
				}
			}
		}

		return $results;
	}

	/**
	 * Import a single product
	 */
	private function import_single_product( $data, $update_existing = false ) {
		$sku   = sanitize_text_field( $data['Product ID'] ?? '' );
		$title = sanitize_text_field( $data['Product Title'] ?? '' );

		if ( empty( $sku ) ) {
			return false;
		}

		// Check if product already exists
		$existing_product_id = wc_get_product_id_by_sku( $sku );

		if ( $existing_product_id && !$update_existing ) {
			return $existing_product_id;
		}

		// Create or update product
		try {
			$product = $existing_product_id ? wc_get_product( $existing_product_id ) : new WC_Product_Simple();

			$product->set_name( $title );
			$product->set_sku( $sku );
			$product->set_description( wp_kses_post( $data['Description'] ?? '' ) );
			$product->set_regular_price( floatval( $data['Price'] ?? 0 ) );
			$product->set_catalog_visibility( 'visible' );
			$product->set_status( 'publish' );
		} catch ( Exception $e ) {
			return false;
		}

		// Set categories
		if ( !empty( $data['Category'] ) ) {
			$this->set_product_categories( $product, $data['Category'] );
		}

		// Product Meta fields (ACF)
		$this->update_acf_fields( $product->get_id(), $data );

		// Set product attributes
		$attribute_config = [
			'grade'      => $data['Grade'] ?? '',
			'for-whom'   => $data['For whom'] ?? '',
			'instrument' => $data['Instrumentation'] ?? '',
		];

		$existing_attributes = $product->get_attributes();

		foreach ( $attribute_config as $attribute_name => $term_name ) {
			if ( !empty( $term_name ) ) {
				$taxonomy = 'pa_' . $attribute_name;

				// Ensure attribute term exists, get its ID
				$term = get_term_by( 'name', $term_name, $taxonomy );

				if ( !$term ) {
					// Create the term if it doesn't exist
					$result = wp_insert_term( $term_name, $taxonomy );
					if ( is_wp_error( $result ) ) {
						continue; // skip if there's an error
					}

					$term_id = $result['term_id'];
				} else {
					$term_id = $term->term_id;
				}

				// Build the WC_Product_Attribute
				$attribute = new WC_Product_Attribute();
				$attribute->set_id( wc_attribute_taxonomy_id_by_name( $taxonomy ) );
				$attribute->set_name( $taxonomy );
				$attribute->set_options( [$term_id] );
				$attribute->set_position( 0 );
				$attribute->set_visible( true );
				$attribute->set_variation( false );

				// Add to existing attributes
				$existing_attributes[$taxonomy] = $attribute;
			}
		}

		$product->set_attributes( $existing_attributes );

		try {
			$product_id = $product->save();

			// debug: get the product after it's been saved and log the attributes
			$product = wc_get_product( $product_id );
			error_log( 'product attributes: ' . print_r( $product->get_attributes(), true ) );
			return $product_id;
		} catch ( Exception $e ) {
			error_log( 'error saving product: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Parse CSV file
	 */
	private function parse_csv( $file_path ) {
		$data    = [];
		$headers = [];

		if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
			$row_count = 0;
			while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
				if ( 0 === $row_count ) {
					$headers = $row;
				} else {
					if ( count( $headers ) === count( $row ) ) {
						$data[] = array_combine( $headers, $row );
					}
				}
				$row_count++;
			}
			fclose( $handle );
		}

		return $data;
	}

	/**
	 * Get attribute term slug
	 */
	private function get_attribute_term_id( $attribute_taxonomy, $attribute_term_name ) {
		$term = get_term_by( 'name', $attribute_term_name, $attribute_taxonomy, 'ARRAY_A' );
		if ( !$term ) {
			$term = wp_insert_term( $attribute_term_name, $attribute_taxonomy );
		}

		return $term['term_id'];
	}

	/**
	 * Create product bundle
	 */
	private function create_product_bundle( $base_sku, $family_data ) {
		// Check if WooCommerce Product Bundles is active
		if ( !class_exists( 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Use full_set_data for bundle creation
		$bundle_data = $family_data['full_set_data'] ?? $family_data['base_data'];

		// Debug: Log the bundle data
		error_log( 'Bundle data for ' . $base_sku . ': ' . print_r( $bundle_data, true ) );

		// Check if bundle already exists
		global $wpdb;
		$existing_bundle_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bundle_base_sku' AND meta_value = %s",
			$base_sku
		) );

		if ( $existing_bundle_id ) {
			// Update existing bundle
			$bundle = wc_get_product( $existing_bundle_id );
			if ( $bundle && is_a( $bundle, 'WC_Product_Bundle' ) ) {
				// Update bundle data
				$bundle_title = $bundle_data['Product Title'] ?? '';
				$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

				$bundle->set_name( $bundle_title . ' - Full Set' );
				$bundle->set_description( wp_kses_post( $bundle_data['Description'] ?? '' ) );

				// Update the price from CSV data
				$bundle_price = floatval( $bundle_data['Price'] ?? 0 );
				error_log( 'Updating existing bundle price for ' . $base_sku . ' to: ' . $bundle_price );
				$bundle->set_regular_price( $bundle_price );

				// Set categories
				if ( !empty( $bundle_data['Category'] ) ) {
					$this->set_product_categories( $bundle, $bundle_data['Category'] );
				}

				// Update custom meta
				$this->update_bundle_meta( $bundle, $base_sku, $bundle_data );

				$bundle->save();

				// Update bundled products
				$this->add_products_to_bundle( $existing_bundle_id, $base_sku, $family_data );

				return $existing_bundle_id;
			}
		}

		// Create new bundle
		$bundle_title = $bundle_data['Product Title'] ?? '';
		$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

		// Create bundle product
		$bundle = new WC_Product_Bundle();
		$bundle->set_name( $bundle_title . ' - Full Set' );
		$bundle->set_sku( $bundle_data['Product ID'] ?? $base_sku );
		$bundle->set_description( wp_kses_post( $bundle_data['Description'] ?? '' ) );

		// Set the price from CSV data
		$bundle_price = floatval( $bundle_data['Price'] ?? 0 );
		error_log( 'Setting new bundle price for ' . $base_sku . ' to: ' . $bundle_price );
		$bundle->set_regular_price( $bundle_price );

		$bundle->set_catalog_visibility( 'visible' );
		$bundle->set_status( 'publish' );

		// Set categories
		if ( !empty( $bundle_data['Category'] ) ) {
			$this->set_product_categories( $bundle, $bundle_data['Category'] );
		}

		// Set custom meta
		$this->update_bundle_meta( $bundle, $base_sku, $bundle_data );

		$bundle_id = $bundle->save();

		// Add bundled products
		if ( $bundle_id ) {
			$result = $this->add_products_to_bundle( $bundle_id, $base_sku, $family_data );
		}

		return $bundle_id;
	}

	/**
	 * Add products to bundle using proper WooCommerce Product Bundles API
	 */
	private function add_products_to_bundle( $bundle_id, $base_sku, $family_data = null ) {
		// Check if WooCommerce Product Bundles is active
		if ( !class_exists( 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Get the bundle product
		$bundle = wc_get_product( $bundle_id );
		if ( !$bundle || !is_a( $bundle, 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Get product IDs from family data if available, otherwise fall back to database query
		$product_ids = [];

		if ( $family_data && isset( $family_data['products'] ) ) {
			// Use the family data which includes all individual products
			$product_ids = $family_data['products'];
			error_log( 'Using family data for bundle. Found ' . count( $product_ids ) . ' products: ' . implode( ', ', $product_ids ) );
		} else {
			// Fallback: Query for all products whose SKU starts with the base SKU
			global $wpdb;
			$like_pattern = $wpdb->esc_like( $base_sku ) . '%';
			$product_ids  = $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_sku'
				AND meta_value LIKE %s",
				$like_pattern
			) );
			error_log( 'Using database query for bundle. Found ' . count( $product_ids ) . ' products: ' . implode( ', ', $product_ids ) );
		}

		if ( empty( $product_ids ) ) {
			error_log( 'No products found for bundle with base SKU: ' . $base_sku );
			return false;
		}

		// Clear existing bundled items for this bundle by setting empty array
		$bundle->set_bundled_data_items( [] );

		// Add each product as a bundled item
		$menu_order    = 0;
		$added_count   = 0;
		$bundled_items = [];

		foreach ( $product_ids as $product_id ) {
			// Get the product to verify it exists and get its SKU
			$product = wc_get_product( $product_id );
			if ( !$product ) {
				error_log( 'Product not found: ' . $product_id );
				continue;
			}

			$sku = $product->get_sku();
			error_log( 'Adding product to bundle: ' . $sku . ' (ID: ' . $product_id . ')' );

			// Create bundled item data with improved configuration for sheet music
			$bundled_item_data = [
				'bundle_id'  => $bundle_id,
				'product_id' => $product_id,
				'menu_order' => $menu_order,
				'meta_data'  => [
					'quantity_min'                          => 1, // Full set includes all parts
					'quantity_max'                          => 1, // One of each part
					'quantity_default'                      => 1, // Default to including all parts
					'optional'                              => 'no', // All parts are required in full set
					'hide_thumbnail'                        => 'no',
					'discount'                              => '', // No discount on individual parts
					'override_variations'                   => 'no',
					'allowed_variations'                    => [],
					'override_default_variation_attributes' => 'no',
					'default_variation_attributes'          => [],
					'single_product_visibility'             => 'visible',
					'cart_visibility'                       => 'visible',
					'order_visibility'                      => 'visible',
					'single_product_price_visibility'       => 'visible',
					'cart_price_visibility'                 => 'visible',
					'order_price_visibility'                => 'visible',
					'priced_individually'                   => 'no', // Bundle has fixed price, not calculated from items
					'shipped_individually'                  => 'no', // All parts shipped together
				],
			];

			// Add to bundled items array
			$bundled_items[] = $bundled_item_data;
			$added_count++;
			$menu_order++;
		}

		// Set all bundled items at once
		$bundle->set_bundled_data_items( $bundled_items );

		// Save the bundle to persist changes
		$bundle->save();

		// Set bundle type for WooCommerce (this is still needed)
		wp_set_object_terms( $bundle_id, 'bundle', 'product_type' );

		// Clear any caches
		clean_post_cache( $bundle_id );
		wc_delete_product_transients( $bundle_id );

		error_log( 'Bundle setup complete. Added ' . $added_count . ' items to bundle ' . $bundle_id );
		return $added_count > 0;
	}

	/**
	 * Set product categories
	 */
	private function set_product_categories( $product, $category_name ) {
		$term = get_term_by( 'name', $category_name, 'product_cat' );

		if ( !$term ) {
			// Create category if it doesn't exist
			$term_result = wp_insert_term( $category_name, 'product_cat' );
			if ( !is_wp_error( $term_result ) ) {
				$term_id = $term_result['term_id'];
			} else {
				return; // Failed to create category
			}
		} else {
			$term_id = $term->term_id;
		}

		// Set the category
		$product->set_category_ids( [$term_id] );
	}

	/**
	 * Get base SKU from full SKU
	 */
	private function get_base_sku( $sku ) {
		$parts = explode( '-', $sku );
		if ( count( $parts ) >= 2 ) {
			return $parts[0] . '-' . $parts[1];
		}
		return $sku;
	}

	/**
	 * Create product attributes from CSV data
	 */
	private function create_product_attributes( $data ) {
		$attribute_config = [
			'grade'      => [
				'name'      => 'Grade',
				'csv_field' => 'Grade',
			],
			'for_whom'   => [
				'name'      => 'For Whom',
				'csv_field' => 'For whom',
			],
			'instrument' => [
				'name'      => 'Instrument',
				'csv_field' => 'Instrumentation',
			],
		];

		$attributes = [];
		foreach ( $attribute_config as $key => $config ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( $config['name'] );
			$attribute->set_id( wc_attribute_taxonomy_id_by_name( $config['name'] ) );
			$attribute->set_options( [sanitize_text_field( $data[$config['csv_field']] ?? '' )] );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$attributes[$key] = $attribute;
		}

		return $attributes;
	}

	/**
	 * Update ACF fields from CSV data
	 */
	private function update_acf_fields( $product_id, $data ) {
		$acf_fields = [
			'byline'            => [
				'value'    => $data['Byline'] ?? '',
				'sanitize' => 'sanitize_text_field',
			],
			'original_url'      => [
				'value'    => $data['Original URL'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'soundcloud_link'   => [
				'value'    => $data['Soundcloud Link'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'soundcloud_link_2' => [
				'value'    => $data['Soundcloud Link 2'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'single_instrument' => [
				'value'    => $data['Single Instrument'] ?? '',
				'sanitize' => 'sanitize_text_field',
			],
			'created_by_import' => [
				'value'    => true,
				'sanitize' => null,
			],
		];

		foreach ( $acf_fields as $field_name => $field_config ) {
			$value = $field_config['value'];
			if ( $field_config['sanitize'] ) {
				$value = call_user_func( $field_config['sanitize'], $value );
			}
			update_field( $field_name, $value, $product_id );
		}
	}

	/**
	 * Update product meta data from CSV data
	 */
	private function update_product_attachments( $product, $data ) {
		$meta_fields = [
			'_product_file_name' => 'Product File Name',
			'_image_file_name'   => 'Image File Name',
			'_sound_filenames'   => 'Sound Filenames (comma-separated)
		Example: f-warmup.mp3, g-warmup.wav',
		];

		foreach ( $meta_fields as $meta_key => $csv_field ) {
			$product->update_meta_data( $meta_key, sanitize_text_field( $data[$csv_field] ?? '' ) );
		}
	}

	/**
	 * Update bundle meta data from CSV data
	 */
	private function update_bundle_meta( $bundle, $base_sku, $data ) {
		$bundle->update_meta_data( '_bundle_base_sku', $base_sku );
		$bundle->update_meta_data( '_grade', sanitize_text_field( $data['Grade'] ?? '' ) );
		$bundle->update_meta_data( '_for_whom', sanitize_text_field( $data['For whom'] ?? '' ) );
		$bundle->update_meta_data( '_byline', sanitize_text_field( $data['Byline'] ?? '' ) );
		$bundle->update_meta_data( '_created_via_import', true );
	}

	/**
	 * Get CSV file information (row count, etc.) for progress tracking
	 */
	public function ajax_get_csv_info() {
		// Verify nonce
		if ( !wp_verify_nonce( $_POST['nonce'] ?? '', 'rhd_csv_import' ) ) {
			wp_die( __( 'Security check failed', 'rhd' ) );
		}

		// Check user permissions
		if ( !current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Insufficient permissions', 'rhd' ) );
		}

		// Handle file upload
		if ( empty( $_FILES['csv_file'] ) || UPLOAD_ERR_OK !== $_FILES['csv_file']['error'] ) {
			wp_send_json_error( __( 'Please select a valid CSV file', 'rhd' ) );
		}

		$file_path = $_FILES['csv_file']['tmp_name'];

		try {
			// Count total rows
			$total_rows = 0;
			if ( ( $handle = fopen( $file_path, 'r' ) ) !== false ) {
				while ( ( $row = fgetcsv( $handle, 0, ',' ) ) !== false ) {
					$total_rows++;
				}
				fclose( $handle );
			}

			// Store file temporarily for chunked processing
			$temp_file_path = wp_upload_dir()['basedir'] . '/rhd_temp_import_' . time() . '.csv';
			if ( !move_uploaded_file( $file_path, $temp_file_path ) ) {
				wp_send_json_error( __( 'Failed to store temporary file', 'rhd' ) );
			}

			wp_send_json_success( [
				'total_rows' => max( 0, $total_rows - 1 ), // Subtract 1 for header row
				'temp_file'  => basename( $temp_file_path ),
				'message'    => sprintf( __( 'Ready to import %d products', 'rhd' ), max( 0, $total_rows - 1 ) ),
			] );
		} catch ( Exception $e ) {
			wp_send_json_error( sprintf( __( 'Failed to analyze CSV: %s', 'rhd' ), $e->getMessage() ) );
		}
	}

	/**
	 * Process CSV in chunks for progress tracking
	 */
	public function ajax_process_chunk() {
		// Verify nonce
		if ( !wp_verify_nonce( $_POST['nonce'] ?? '', 'rhd_csv_import' ) ) {
			wp_die( __( 'Security check failed', 'rhd' ) );
		}

		// Check user permissions
		if ( !current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Insufficient permissions', 'rhd' ) );
		}

		$temp_file       = sanitize_text_field( $_POST['temp_file'] ?? '' );
		$offset          = intval( $_POST['offset'] ?? 0 );
		$chunk_size      = intval( $_POST['chunk_size'] ?? 50 );
		$update_existing = isset( $_POST['update_existing'] ) && '1' === $_POST['update_existing'];

		if ( empty( $temp_file ) ) {
			wp_send_json_error( __( 'No temporary file specified', 'rhd' ) );
		}

		$file_path = wp_upload_dir()['basedir'] . '/' . $temp_file;
		if ( !file_exists( $file_path ) ) {
			wp_send_json_error( __( 'Temporary file not found', 'rhd' ) );
		}

		try {
			$csv_data   = $this->parse_csv( $file_path );
			$total_rows = count( $csv_data );

			// Get the chunk of data to process
			$chunk_data = array_slice( $csv_data, $offset, $chunk_size );

			$results = [
				'products_imported' => 0,
				'products_updated'  => 0,
				'errors'            => [],
			];

			// Process the chunk
			foreach ( $chunk_data as $row ) {
				if ( empty( $row['Product ID'] ) ) {
					continue;
				}

				// Skip Full Set products in chunk processing - they'll be handled as bundles
				if ( isset( $row['Single Instrument'] ) && strtolower( trim( $row['Single Instrument'] ) ) === 'full set' ) {
					continue;
				}

				try {
					$product_id = $this->import_single_product( $row, $update_existing );

					if ( $product_id ) {
						if ( $update_existing && get_post_meta( $product_id, '_created_via_import', true ) ) {
							$results['products_updated']++;
						} else {
							$results['products_imported']++;
						}
					}
				} catch ( Exception $e ) {
					$results['errors'][] = sprintf(
						__( 'Error importing product %s: %s', 'rhd' ),
						$row['Product ID'] ?? 'Unknown',
						$e->getMessage()
					);
				}
			}

			$processed   = $offset + count( $chunk_data );
			$is_complete = $processed >= $total_rows;

			wp_send_json_success( [
				'processed'   => $processed,
				'total'       => $total_rows,
				'is_complete' => $is_complete,
				'results'     => $results,
				'message'     => sprintf( __( 'Processed %d of %d products', 'rhd' ), $processed, $total_rows ),
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( sprintf( __( 'Chunk processing failed: %s', 'rhd' ), $e->getMessage() ) );
		}
	}

	/**
	 * Finalize import by creating bundles for Full Set products
	 */
	public function ajax_finalize_import() {
		// Verify nonce
		if ( !wp_verify_nonce( $_POST['nonce'] ?? '', 'rhd_csv_import' ) ) {
			wp_die( __( 'Security check failed', 'rhd' ) );
		}

		// Check user permissions
		if ( !current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Insufficient permissions', 'rhd' ) );
		}

		$temp_file      = sanitize_text_field( $_POST['temp_file'] ?? '' );
		$create_bundles = isset( $_POST['create_bundles'] ) && '1' === $_POST['create_bundles'];

		if ( empty( $temp_file ) ) {
			wp_send_json_error( __( 'No temporary file specified', 'rhd' ) );
		}

		$file_path = wp_upload_dir()['basedir'] . '/' . $temp_file;
		if ( !file_exists( $file_path ) ) {
			wp_send_json_error( __( 'Temporary file not found: ' . $file_path, 'rhd' ) );
		}

		try {
			// Parse CSV to rebuild product families
			$csv_data = $this->parse_csv( $file_path );

			$product_families = [];

			// Rebuild product families from imported products (excluding Full Set products)
			foreach ( $csv_data as $row_index => $row ) {
				if ( empty( $row['Product ID'] ) ) {
					continue;
				}

				// Skip Full Set products in first pass
				if ( isset( $row['Single Instrument'] ) && strtolower( trim( $row['Single Instrument'] ) ) === 'full set' ) {
					continue;
				}

				$product_id = wc_get_product_id_by_sku( $row['Product ID'] );
				if ( !$product_id ) {
					continue;
				}

				$base_sku = $this->get_base_sku( $row['Product ID'] );
				if ( !isset( $product_families[$base_sku] ) ) {
					$product_families[$base_sku] = [
						'products'      => [],
						'full_set_data' => null,
						'base_data'     => $row,
					];
				}
				$product_families[$base_sku]['products'][] = $product_id;
			}

			// Second pass: Process Full Set products and create bundles
			foreach ( $csv_data as $row ) {
				if ( empty( $row['Product ID'] ) ) {
					continue;
				}

				// Only process Full Set products in second pass
				if ( isset( $row['Single Instrument'] ) && strtolower( trim( $row['Single Instrument'] ) ) === 'full set' ) {
					$base_sku = $this->get_base_sku( $row['Product ID'] );

					if ( isset( $product_families[$base_sku] ) ) {
						$product_families[$base_sku]['full_set_data'] = $row;
					}
				}
			}

			$results = [
				'bundles_created' => 0,
				'errors'          => [],
			];

			// Create bundles for all families with Full Set data
			foreach ( $product_families as $base_sku => $family_data ) {
				if ( $family_data['full_set_data'] && count( $family_data['products'] ) > 0 ) {
					try {
						error_log( 'Processing bundle for family: ' . $base_sku );
						$bundle_id = $this->create_product_bundle( $base_sku, $family_data );
						if ( $bundle_id ) {
							$results['bundles_created']++;
							error_log( 'Successfully processed bundle: ' . $bundle_id . ' for family: ' . $base_sku );
						}
					} catch ( Exception $e ) {
						$error_msg = sprintf(
							__( 'Error creating bundle for %s: %s', 'rhd' ),
							$base_sku,
							$e->getMessage()
						);
						$results['errors'][] = $error_msg;
						error_log( 'Error processing bundle for family: ' . $base_sku . ' - ' . $e->getMessage() );
					}
				}
			}

			// Clean up temp file
			unlink( $file_path );

			wp_send_json_success( [
				'message' => __( 'Import finalization completed', 'rhd' ),
				'results' => $results,
			] );

		} catch ( Exception $e ) {
			$error_message = sprintf( __( 'Finalization failed: %s', 'rhd' ), $e->getMessage() );
			wp_send_json_error( $error_message );
		} catch ( Error $e ) {
			$error_message = sprintf( __( 'Finalization failed with fatal error: %s', 'rhd' ), $e->getMessage() );
			wp_send_json_error( $error_message );
		} catch ( Throwable $e ) {
			$error_message = sprintf( __( 'Finalization failed with throwable: %s', 'rhd' ), $e->getMessage() );
			wp_send_json_error( $error_message );
		}
	}

	/**
	 * Display custom bundle template
	 */
	public function display_custom_bundle_template() {
		global $product;

		// Only show for bundle products
		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return;
		}

		// Include our custom template
		$template_path = plugin_dir_path( __FILE__ ) . 'templates/single-product/add-to-cart/bundle.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_frontend_scripts() {
		if ( is_product() ) {
			wp_enqueue_script(
				'rhd-csharp-importer-frontend',
				RHD_CSHARP_IMPORTER_URL . 'assets/frontend.js',
				['jquery'],
				RHD_CSHARP_IMPORTER_VERSION,
				true
			);

			wp_localize_script( 'rhd-csharp-importer-frontend', 'rhdImporter', [
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rhd_csv_import' ),
				'strings' => [
					'importing' => __( 'Importing products...', 'rhd' ),
					'success'   => __( 'Import completed successfully!', 'rhd' ),
					'error'     => __( 'Import failed. Please try again.', 'rhd' ),
				],
			] );
		}
	}

	/**
	 * Hide default add-to-cart form for bundle products
	 */
	public function maybe_hide_default_add_to_cart() {
		global $product;

		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return;
		}

		// This action is hooked into woocommerce_single_product_summary, which is typically
		// where the default add-to-cart form is rendered. By returning early, we prevent
		// the default form from being output.
		return;
	}

	/**
	 * Filter to prevent purchasing bundle products directly.
	 */
	public function maybe_hide_purchasable( $purchasable, $product ) {
		if ( is_a( $product, 'WC_Product_Bundle' ) ) {
			return false;
		}
		return $purchasable;
	}
}

// Initialize the plugin
new RHD_CSharp_Product_Importer();
