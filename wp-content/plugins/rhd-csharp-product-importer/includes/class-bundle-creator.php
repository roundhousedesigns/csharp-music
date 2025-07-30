<?php
/**
 * Bundle creation class
 */
class RHD_CSharp_Bundle_Creator {

	/**
	 * Create product bundle
	 */
	public function create_product_bundle( $base_sku, $family_data, $create_zip = true, $file_handler = null ) {
		// Check if WooCommerce Product Bundles is active
		if ( !class_exists( 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Use full_set_data for bundle creation
		$bundle_data = $family_data['full_set_data'] ?? $family_data['base_data'];

		// Check if bundle already exists
		global $wpdb;
		$existing_bundle_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_bundle_base_sku' AND meta_value = %s",
			$base_sku
		) );

		if ( $existing_bundle_id ) {
			// Update existing bundle
			return $this->update_existing_bundle( $existing_bundle_id, $base_sku, $bundle_data, $family_data, $create_zip, $file_handler );
		}

		// Create new bundle
		return $this->create_new_bundle( $base_sku, $bundle_data, $family_data, $create_zip, $file_handler );
	}

	/**
	 * Update existing bundle
	 */
	private function update_existing_bundle( $bundle_id, $base_sku, $bundle_data, $family_data, $create_zip = true, $file_handler = null ) {
		$bundle = wc_get_product( $bundle_id );
		if ( !$bundle || !is_a( $bundle, 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Update bundle data
		$bundle_title = $bundle_data['Product Title'] ?? '';
		$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

		$bundle->set_name( $bundle_title . ' - Full Set' );
		$bundle->set_description( wp_kses_post( $bundle_data['Description'] ?? '' ) );

		// Update the price from CSV data
		$bundle_price = floatval( $bundle_data['Price'] ?? 0 );
		$bundle->set_regular_price( $bundle_price );

		// Mark bundle as downloadable
		$bundle->set_downloadable( true );
		$bundle->set_virtual( true );

		// Set categories
		if ( !empty( $bundle_data['Category'] ) ) {
			$this->set_product_categories( $bundle, $bundle_data['Category'] );
		}

		// Update custom meta
		$this->update_bundle_meta( $bundle, $base_sku, $bundle_data );

		$bundle->save();

		// Import bundle files (including ZIP creation) only if requested
		if ( $create_zip ) {
			if ( !$file_handler ) {
				$file_handler = new RHD_CSharp_File_Handler();
			}
			$file_handler->import_product_files( $bundle, $bundle_data );
		}

		// Update bundled products
		$this->add_products_to_bundle( $bundle_id, $base_sku, $family_data );

		return $bundle_id;
	}

	/**
	 * Create new bundle
	 */
	private function create_new_bundle( $base_sku, $bundle_data, $family_data, $create_zip = true, $file_handler = null ) {
		$bundle_title = $bundle_data['Product Title'] ?? '';
		$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

		// Create bundle product
		$bundle = new WC_Product_Bundle();
		$bundle->set_name( $bundle_title . ' - Full Set' );
		$bundle->set_sku( $bundle_data['Product ID'] ?? $base_sku );
		$bundle->set_description( wp_kses_post( $bundle_data['Description'] ?? '' ) );

		// Set the price from CSV data
		$bundle_price = floatval( $bundle_data['Price'] ?? 0 );
		$bundle->set_regular_price( $bundle_price );

		// Mark bundle as downloadable
		$bundle->set_downloadable( true );
		$bundle->set_virtual( true );

		$bundle->set_catalog_visibility( 'visible' );
		$bundle->set_status( 'publish' );

		// Set categories
		if ( !empty( $bundle_data['Category'] ) ) {
			$this->set_product_categories( $bundle, $bundle_data['Category'] );
		}

		// Set custom meta
		$this->update_bundle_meta( $bundle, $base_sku, $bundle_data );

		$bundle_id = $bundle->save();

		// Import and associate product files (including ZIP creation) only if requested
		if ( $bundle_id && $create_zip ) {
			if ( !$file_handler ) {
				$file_handler = new RHD_CSharp_File_Handler();
			}
			$file_handler->import_product_files( $bundle, $bundle_data );
		}

		// Add bundled products
		if ( $bundle_id ) {
			$this->add_products_to_bundle( $bundle_id, $base_sku, $family_data );
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
		}

		if ( empty( $product_ids ) ) {
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
				continue;
			}

			// Create bundled item data
			$bundled_item_data = [
				'bundle_id'  => $bundle_id,
				'product_id' => $product_id,
				'menu_order' => $menu_order,
				'meta_data'  => [
					'quantity_min'                          => 1,
					'quantity_max'                          => 1,
					'quantity_default'                      => 1,
					'optional'                              => 'no',
					'hide_thumbnail'                        => 'no',
					'discount'                              => '',
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
					'priced_individually'                   => 'no',
					'shipped_individually'                  => 'no',
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

		// Set bundle type for WooCommerce
		wp_set_object_terms( $bundle_id, 'bundle', 'product_type' );

		// Clear any caches
		clean_post_cache( $bundle_id );
		wc_delete_product_transients( $bundle_id );

		return $added_count > 0;
	}

	/**
	 * Finalize import by creating bundles for Full Set products
	 */
	public function finalize_import( $file_path ) {
		$csv_parser       = new RHD_CSharp_CSV_Parser();
		$product_importer = new RHD_CSharp_Product_Importer();

		// Parse CSV to rebuild product families
		$csv_data = $csv_parser->parse( $file_path );

		$product_families = [];

		// Rebuild product families from imported products (excluding Full Set products)
		foreach ( $csv_data as $row ) {
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
					error_log( 'RHD Import: Creating bundle for base SKU: ' . $base_sku );
					$bundle_id = $this->create_product_bundle( $base_sku, $family_data );
					if ( $bundle_id ) {
						$results['bundles_created']++;
						error_log( 'RHD Import: Successfully created bundle ' . $bundle_id . ' for base SKU: ' . $base_sku );
					} else {
						$error_msg = sprintf(
							__( 'Failed to create bundle for %s: Unknown error', 'rhd' ),
							$base_sku
						);
						$results['errors'][] = $error_msg;
						error_log( 'RHD Import: ' . $error_msg );
					}
				} catch ( Exception $e ) {
					$error_msg = sprintf(
						__( 'Error creating bundle for %s: %s', 'rhd' ),
						$base_sku,
						$e->getMessage()
					);
					$results['errors'][] = $error_msg;
					error_log( 'RHD Import: Exception creating bundle for ' . $base_sku . ': ' . $e->getMessage() );
				} catch ( Error $e ) {
					$error_msg = sprintf(
						__( 'Fatal error creating bundle for %s: %s', 'rhd' ),
						$base_sku,
						$e->getMessage()
					);
					$results['errors'][] = $error_msg;
					error_log( 'RHD Import: Fatal error creating bundle for ' . $base_sku . ': ' . $e->getMessage() );
				}
			}
		}

		return $results;
	}

	/**
	 * Prepare bundle families data for chunked processing
	 */
	public function prepare_bundle_families( $file_path ) {
		$csv_parser = new RHD_CSharp_CSV_Parser();

		// Parse CSV to rebuild product families
		$csv_data = $csv_parser->parse( $file_path );

		$product_families = [];

		// Rebuild product families from imported products (excluding Full Set products)
		foreach ( $csv_data as $row ) {
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

		// Second pass: Process Full Set products and add their data
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

		// Filter to only return families that have Full Set data and products
		$valid_families = [];
		foreach ( $product_families as $base_sku => $family_data ) {
			if ( $family_data['full_set_data'] && count( $family_data['products'] ) > 0 ) {
				$valid_families[$base_sku] = $family_data;
			}
		}

		return $valid_families;
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
	 * Update bundle meta data from CSV data
	 */
	private function update_bundle_meta( $bundle, $base_sku, $data ) {

		$bundle->update_meta_data( '_bundle_base_sku', $base_sku );

		// Set global product attributes using shared method
		$attribute_config = [
			'grade'    => $data['Grade'] ?? '',
			'for-whom' => $data['For whom'] ?? '',
			'byline'   => $data['Byline'] ?? '',
		];

		$product_importer = new RHD_CSharp_Product_Importer();
		$product_importer->set_wc_product_attributes( $bundle, $attribute_config );
		$bundle->save();

		// Set meta fields
		$meta_fields = [
			'original_url'        => $data['Original URL'] ?? '',
			'soundcloud_link_1'   => $data['Soundcloud Link'] ?? '',
			'soundcloud_link_2'   => $data['Soundcloud Link 2'] ?? '',
			'rhd_csharp_importer' => true,
		];

		$pod = pods( 'product', $bundle->get_id() );
		$pod->save( $meta_fields );
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
}
