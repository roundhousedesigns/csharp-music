<?php
/**
 * Bundle creation class
 */
class RHD_CSharp_Bundle_Creator {

	/**
	 * Create product bundle
	 *
	 * @param  string                  $base_sku     The base SKU of the bundle
	 * @param  array                   $family_data  The family data
	 * @param  RHD_CSharp_File_Handler $file_handler The file handler
	 * @return int|false               The bundle ID or false if the bundle could not be created
	 */
	public function create_product_bundle( $base_sku, $family_data, $file_handler = null ) {
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
			return $this->update_existing_bundle( $existing_bundle_id, $base_sku, $bundle_data, $family_data, $file_handler );
		}

		// Create new bundle
		return $this->create_new_bundle( $base_sku, $bundle_data, $family_data, $file_handler );
	}

	/**
	 * Update existing bundle
	 */
	private function update_existing_bundle( $bundle_id, $base_sku, $bundle_data, $family_data, $file_handler = null ) {
		$bundle = wc_get_product( $bundle_id );
		if ( !$bundle || !is_a( $bundle, 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Update bundle data
		$bundle_title = $bundle_data['Product Title'] ?? '';
		$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

		// $bundle->set_name( $bundle_title . ' - Full Set' );
		$bundle->set_name( $bundle_title );
		$bundle->set_description( wp_kses_post( $bundle_data['Description'] ?? '' ) );

		// Set custom slug for digital bundle (downloadable)
		$slug_base = sanitize_title( $bundle_title );
		$bundle->set_slug( $slug_base . '-d' );

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

		// Import bundle files: attach direct product file and image
		if ( !$file_handler ) {
			$file_handler = new RHD_CSharp_File_Handler();
		}
		$file_handler->import_product_files( $bundle, $bundle_data, true ); // Always update existing for bundles

		// Update bundled products
		$this->add_products_to_bundle( $bundle_id, $base_sku, $family_data );

		return $bundle_id;
	}

	/**
	 * Create new bundle
	 *
	 * @param  string                  $base_sku     The base SKU of the bundle
	 * @param  array                   $bundle_data  The bundle data
	 * @param  array                   $family_data  The family data
	 * @param  RHD_CSharp_File_Handler $file_handler The file handler
	 * @return int|false               The bundle ID or false if the bundle could not be created
	 */
	private function create_new_bundle( $base_sku, $bundle_data, $family_data, $file_handler = null ) {
		$bundle_title = $bundle_data['Product Title'] ?? '';
		$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

		// Create bundle product
		$bundle = new WC_Product_Bundle();
		// $bundle->set_name( $bundle_title . ' - Full Set' );
		$bundle->set_name( $bundle_title );
		$bundle->set_sku( $bundle_data['Product ID'] ?? $base_sku );
		$bundle->set_description( wp_kses_post( $bundle_data['Description'] ?? '' ) );

		// Set custom slug for digital bundle (downloadable)
		$slug_base = sanitize_title( $bundle_title );
		$bundle->set_slug( $slug_base . '-d' );

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

		// Import and associate product files: attach direct product file and image
		if ( $bundle_id ) {
			if ( !$file_handler ) {
				$file_handler = new RHD_CSharp_File_Handler();
			}
			$file_handler->import_product_files( $bundle, $bundle_data, true ); // Always update existing for bundles
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

		// Determine bundle type to filter singles appropriately (digital vs hardcopy)
		$is_digital_bundle = $bundle->is_downloadable();

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

			// Filter by SKU suffix: -D for digital singles; no -D for hardcopy singles
			$single_sku      = $product->get_sku();
			$single_sku_is_d = is_string( $single_sku ) && preg_match( '/-D$/i', $single_sku );
			if ( $is_digital_bundle && !$single_sku_is_d ) {
				continue;
			}
			if ( !$is_digital_bundle && $single_sku_is_d ) {
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
	 * Finalize import by creating bundles and grouped products for Full Set products
	 */
	public function finalize_import( $file_path ) {
		$csv_parser              = new RHD_CSharp_CSV_Parser();
		$product_importer        = new RHD_CSharp_Product_Importer();
		$grouped_product_creator = new RHD_CSharp_Grouped_Product_Creator();

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
					'hardcopy_data' => null,
					'base_data'     => $row,
				];
			}
			$product_families[$base_sku]['products'][] = $product_id;
		}

		// Second pass: Process Full Set products and store their data by type (Digital/Hardcopy/Group)
		foreach ( $csv_data as $row ) {
			if ( empty( $row['Product ID'] ) ) {
				continue;
			}

			// Only process Full Set products in second pass
			if ( isset( $row['Single Instrument'] ) && strtolower( trim( $row['Single Instrument'] ) ) === 'full set' ) {
				$base_sku        = $this->get_base_sku( $row['Product ID'] );
				$digital_or_hard = strtolower( trim( $row['Digital/Hardcopy/Group'] ?? 'digital' ) );

				if ( !isset( $product_families[$base_sku] ) ) {
					$product_families[$base_sku] = [
						'products'      => [],
						'full_set_data' => null,
						'hardcopy_data' => null,
						'base_data'     => $row,
					];
				}

				// Store data based on Digital/Hardcopy/Group type
				if ( 'digital' === $digital_or_hard ) {
					$product_families[$base_sku]['full_set_data'] = $row;
				} elseif ( 'hardcopy' === $digital_or_hard ) {
					$product_families[$base_sku]['hardcopy_data'] = $row;
				}
			}
		}

		$results = [
			'bundles_created'          => 0,
			'grouped_products_created' => 0,
			'errors'                   => [],
		];

		// Create bundles and grouped products for all families
		foreach ( $product_families as $base_sku => $family_data ) {
			$bundle_id = null;

			// Create digital bundle if we have full set digital data
			if ( $family_data['full_set_data'] && count( $family_data['products'] ) > 0 ) {
				try {
					$bundle_id = $this->create_product_bundle( $base_sku, $family_data );
					if ( $bundle_id ) {
						$results['bundles_created']++;
					} else {
						$error_msg = sprintf(
							__( 'Failed to create bundle for %s: Unknown error', 'rhd' ),
							$base_sku
						);
						$results['errors'][] = $error_msg;
					}
				} catch ( Exception $e ) {
					$error_msg = sprintf(
						__( 'Error creating bundle for %s: %s', 'rhd' ),
						$base_sku,
						$e->getMessage()
					);
					$results['errors'][] = $error_msg;
				} catch ( Error $e ) {
					$error_msg = sprintf(
						__( 'Fatal error creating bundle for %s: %s', 'rhd' ),
						$base_sku,
						$e->getMessage()
					);
					$results['errors'][] = $error_msg;
				}
			}

			// Create grouped product if we have either digital bundle or hardcopy data
			if ( $bundle_id || $family_data['hardcopy_data'] ) {
				try {
					$grouped_id = $grouped_product_creator->create_grouped_product( $base_sku, $family_data, $bundle_id );
					if ( $grouped_id ) {
						$results['grouped_products_created']++;
					} else {
						$error_msg = sprintf(
							__( 'Failed to create grouped product for %s: Unknown error', 'rhd' ),
							$base_sku
						);
						$results['errors'][] = $error_msg;
					}
				} catch ( Exception $e ) {
					$error_msg = sprintf(
						__( 'Error creating grouped product for %s: %s', 'rhd' ),
						$base_sku,
						$e->getMessage()
					);
					$results['errors'][] = $error_msg;
				} catch ( Error $e ) {
					$error_msg = sprintf(
						__( 'Fatal error creating grouped product for %s: %s', 'rhd' ),
						$base_sku,
						$e->getMessage()
					);
					$results['errors'][] = $error_msg;
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
		// Debug removed

		$product_families = [];

		// Rebuild product families from imported products (excluding Full Set products)
		$individual_products_found = 0;
		foreach ( $csv_data as $row ) {
			if ( empty( $row['Product ID'] ) ) {
				continue;
			}

			$digital_or_hard   = strtolower( trim( $row['Digital/Hardcopy/Group'] ?? '' ) );
			$single_instrument = strtolower( trim( $row['Single Instrument'] ?? '' ) );

			// Debug: Show available fields for first few rows
			if ( $individual_products_found < 3 ) {
				// Debug removed
			}

			// Skip Full Set products and Group rows in first pass
			if ( 'full set' === $single_instrument ) {
				continue;
			}
			if ( 'group' === $digital_or_hard ) {
				continue;
			}

			$product_id = wc_get_product_id_by_sku( $row['Product ID'] );
			if ( !$product_id ) {
				continue;
			}

			$individual_products_found++;
			$base_sku = $this->get_base_sku( $row['Product ID'] );
			if ( !isset( $product_families[$base_sku] ) ) {
				$product_families[$base_sku] = [
					'products'      => [],
					'full_set_data' => null,
					'hardcopy_data' => null,
					'base_data'     => $row,
				];
			}
			$product_families[$base_sku]['products'][] = $product_id;
		}
		// Debug removed

		// Second pass: Process Full Set products and Group rows
		$full_set_found   = 0;
		$group_rows_found = 0;
		foreach ( $csv_data as $row ) {
			if ( empty( $row['Product ID'] ) ) {
				continue;
			}

			$digital_or_hard   = strtolower( trim( $row['Digital/Hardcopy/Group'] ?? '' ) );
			$single_instrument = strtolower( trim( $row['Single Instrument'] ?? '' ) );
			$base_sku          = $this->get_base_sku( $row['Product ID'] );

			// Process Full Set products
			if ( 'full set' === $single_instrument ) {
				$full_set_found++;
				// Debug removed

				if ( !isset( $product_families[$base_sku] ) ) {
					$product_families[$base_sku] = [
						'products'      => [],
						'full_set_data' => null,
						'hardcopy_data' => null,
						'base_data'     => $row,
					];
					// Debug removed
				}

				// Store data based on Digital/Hardcopy/Group type
				if ( 'digital' === $digital_or_hard ) {
					$product_families[$base_sku]['full_set_data'] = $row;
				} elseif ( in_array( $digital_or_hard, ['hardcopy', 'hardcover'] ) ) {
					$product_families[$base_sku]['hardcopy_data'] = $row;
				}
			}

			// Process Group rows for base data (clean title)
			if ( 'group' === $digital_or_hard ) {
				$group_rows_found++;

				if ( !isset( $product_families[$base_sku] ) ) {
					$product_families[$base_sku] = [
						'products'      => [],
						'full_set_data' => null,
						'hardcopy_data' => null,
						'base_data'     => $row,
					];
				} else {
					// Update base_data with Group row data (it has the clean title)
					$product_families[$base_sku]['base_data'] = $row;
				}
			}
		}
		// Debug removed

		// Filter to only return families that have at least digital data or hardcopy data with products
		$valid_families = [];
		foreach ( $product_families as $base_sku => $family_data ) {
			$has_digital_bundle = $family_data['full_set_data'] && count( $family_data['products'] ) > 0;
			$has_hardcopy       = $family_data['hardcopy_data'];

			if ( $has_digital_bundle || $has_hardcopy ) {
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
			'difficulty'    => $data['Difficulty'] ?? '',
			'ensemble-type' => $data['Ensemble Type'] ?? '',
			'byline'        => $data['Byline'] ?? '',
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
	 * Add product tag
	 */
	private function add_product_tag( $product, $tag_slug ) {
		// Get or create the tag
		$tag = get_term_by( 'slug', $tag_slug, 'product_tag' );

		if ( !$tag ) {
			// Create tag if it doesn't exist
			$tag_result = wp_insert_term( $tag_slug, 'product_tag' );
			if ( !is_wp_error( $tag_result ) ) {
				$tag_id = $tag_result['term_id'];
			} else {
				return; // Failed to create tag
			}
		} else {
			$tag_id = $tag->term_id;
		}

		// Get existing tags and add the new one
		$existing_tag_ids = $product->get_tag_ids();
		if ( !in_array( $tag_id, $existing_tag_ids ) ) {
			$existing_tag_ids[] = $tag_id;
			$product->set_tag_ids( $existing_tag_ids );
		}
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
