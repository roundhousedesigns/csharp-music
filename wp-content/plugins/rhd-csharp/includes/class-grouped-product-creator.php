<?php
/**
 * Grouped product creation class
 */
class RHD_CSharp_Grouped_Product_Creator {

	/**
	 * Create grouped product for a product family
	 *
	 * @param  string    $base_sku    The base SKU (like CB-1001)
	 * @param  array     $family_data The family data containing digital bundle and hardcopy data
	 * @param  int       $bundle_id   The ID of the digital bundle product
	 * @return int|false The grouped product ID or false if creation failed
	 */
	public function create_grouped_product( $base_sku, $family_data, $bundle_id ) {
		// Check if grouped product already exists
		$existing_grouped_id = $this->get_existing_grouped_product( $base_sku );

		if ( $existing_grouped_id ) {
			return $this->update_existing_grouped_product( $existing_grouped_id, $base_sku, $family_data, $bundle_id );
		}

		$result = $this->create_new_grouped_product( $base_sku, $family_data, $bundle_id );

		return $result;
	}

	/**
	 * Check if grouped product already exists for base SKU
	 */
	private function get_existing_grouped_product( $base_sku ) {
		global $wpdb;

		$result = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_grouped_base_sku' AND meta_value = %s",
			$base_sku
		) );

		return $result ? (int) $result : false;
	}

	/**
	 * Create new grouped product
	 */
	private function create_new_grouped_product( $base_sku, $family_data, $bundle_id ) {
		// Get the base title from full set data, removing "Full Set" and "Digital"/"Hardcopy"
		$base_title = $this->get_base_title( $family_data );

		// Check if a product with the base SKU already exists
		$existing_product_id = wc_get_product_id_by_sku( $base_sku );
		
		if ( $existing_product_id ) {
			// Convert existing product to grouped product
			$grouped_product = new WC_Product_Grouped( $existing_product_id );
		} else {
			// Create new grouped product
			$grouped_product = new WC_Product_Grouped();
			$grouped_product->set_sku( $base_sku );
		}
		
		$grouped_product->set_name( $base_title );
		$grouped_product->set_status( 'publish' );
		$grouped_product->set_catalog_visibility( 'visible' );

		// Set description from full set data
		$description = '';
		if ( isset( $family_data['full_set_data']['Description'] ) ) {
			$description = wp_kses_post( $family_data['full_set_data']['Description'] );
		} elseif ( isset( $family_data['hardcopy_data']['Description'] ) ) {
			$description = wp_kses_post( $family_data['hardcopy_data']['Description'] );
		}
		$grouped_product->set_description( $description );

		// Set categories from full set data
		$category = '';
		if ( isset( $family_data['full_set_data']['Category'] ) ) {
			$category = $family_data['full_set_data']['Category'];
		} elseif ( isset( $family_data['hardcopy_data']['Category'] ) ) {
			$category = $family_data['hardcopy_data']['Category'];
		}

		if ( !empty( $category ) ) {
			$this->set_product_categories( $grouped_product, $category );
		}

		// Set attributes from full set data
		$this->set_grouped_product_attributes( $grouped_product, $family_data );

		// Save the grouped product
		$grouped_id = $grouped_product->save();

		if ( $grouped_id ) {
			// Ensure the product type is set to grouped
			wp_set_object_terms( $grouped_id, 'grouped', 'product_type' );
			
			// Add custom meta to track this as a grouped product for our base SKU
			update_post_meta( $grouped_id, '_grouped_base_sku', $base_sku );

			// Update Pods fields
			$this->update_grouped_meta_fields( $grouped_id, $family_data );

			// Set up the grouped products (digital bundle + hardcopy product)
			$this->setup_grouped_children( $grouped_id, $base_sku, $family_data, $bundle_id );
		}

		return $grouped_id;
	}

	/**
	 * Update existing grouped product
	 */
	private function update_existing_grouped_product( $grouped_id, $base_sku, $family_data, $bundle_id ) {
		$grouped_product = wc_get_product( $grouped_id );
		if ( !$grouped_product || !is_a( $grouped_product, 'WC_Product_Grouped' ) ) {
			return false;
		}

		// Update basic data
		$base_title = $this->get_base_title( $family_data );
		$grouped_product->set_name( $base_title );

		// Update description
		$description = '';
		if ( isset( $family_data['full_set_data']['Description'] ) ) {
			$description = wp_kses_post( $family_data['full_set_data']['Description'] );
		} elseif ( isset( $family_data['hardcopy_data']['Description'] ) ) {
			$description = wp_kses_post( $family_data['hardcopy_data']['Description'] );
		}
		$grouped_product->set_description( $description );

		// Update categories
		$category = '';
		if ( isset( $family_data['full_set_data']['Category'] ) ) {
			$category = $family_data['full_set_data']['Category'];
		} elseif ( isset( $family_data['hardcopy_data']['Category'] ) ) {
			$category = $family_data['hardcopy_data']['Category'];
		}

		if ( !empty( $category ) ) {
			$this->set_product_categories( $grouped_product, $category );
		}

		// Update attributes
		$this->set_grouped_product_attributes( $grouped_product, $family_data );

		// Save changes
		$grouped_product->save();

		// Update Pods fields
		$this->update_grouped_meta_fields( $grouped_id, $family_data );

		// Update grouped children
		$this->setup_grouped_children( $grouped_id, $base_sku, $family_data, $bundle_id );

		return $grouped_id;
	}

	/**
	 * Get base title by removing suffixes like "Full Set", "Digital", etc.
	 */
	private function get_base_title( $family_data ) {
		$title = '';

		// Try to get title from full set data first
		if ( isset( $family_data['full_set_data']['Product Title'] ) ) {
			$title = $family_data['full_set_data']['Product Title'];
		} elseif ( isset( $family_data['hardcopy_data']['Product Title'] ) ) {
			$title = $family_data['hardcopy_data']['Product Title'];
		} elseif ( isset( $family_data['base_data']['Product Title'] ) ) {
			$title = $family_data['base_data']['Product Title'];
		}

		// Remove common suffixes
		$title = preg_replace( '/\s*-\s*(Full Set|Digital|Hardcopy)\s*(Digital|Hardcopy)?\s*$/i', '', $title );
		$title = trim( $title );

		return $title;
	}

	/**
	 * Set up the children of the grouped product (digital bundle + hardcopy product)
	 */
	private function setup_grouped_children( $grouped_id, $base_sku, $family_data, $bundle_id ) {
		$children = [];

		// Add the digital bundle
		if ( $bundle_id ) {
			$children[] = $bundle_id;
		}

		// Create or find the hardcopy product
		$hardcopy_id = $this->create_or_get_hardcopy_product( $base_sku, $family_data );
		if ( $hardcopy_id ) {
			$children[] = $hardcopy_id;
		}

		// Set the children for the grouped product
		if ( !empty( $children ) ) {
			update_post_meta( $grouped_id, '_children', $children );
			wc_delete_product_transients( $grouped_id );
		}
	}

	/**
	 * Create or get hardcopy product for the family
	 */
	private function create_or_get_hardcopy_product( $base_sku, $family_data ) {
		// Look for hardcopy data in family_data
		$hardcopy_data = null;
		if ( isset( $family_data['hardcopy_data'] ) ) {
			$hardcopy_data = $family_data['hardcopy_data'];
		}

		if ( !$hardcopy_data ) {
			// No hardcopy data available, skip creation
			return false;
		}

		$hardcopy_sku = $hardcopy_data['Product ID'] ?? $base_sku . '-HC';

		// If a product with this SKU exists, ensure it's a bundle; otherwise convert
		$existing_id = wc_get_product_id_by_sku( $hardcopy_sku );
		$bundle      = null;
		if ( $existing_id ) {
			$existing = wc_get_product( $existing_id );
			if ( $existing && is_a( $existing, 'WC_Product_Bundle' ) ) {
				$bundle = $existing;
			} else {
				// Convert existing product to a bundle
				$bundle = new WC_Product_Bundle( $existing_id );
				wp_set_object_terms( $existing_id, 'bundle', 'product_type' );
			}
		} else {
			$bundle = new WC_Product_Bundle();
		}

		// Name: use clean title (remove suffixes like "- Full Set") similar to digital bundles
		$bundle_title = $hardcopy_data['Product Title'] ?? '';
		$bundle_title = preg_replace( '/\s*-\s*Full Set$/i', '', $bundle_title );

		$bundle->set_name( $bundle_title );
		$bundle->set_sku( $hardcopy_sku );
		$bundle->set_description( wp_kses_post( $hardcopy_data['Description'] ?? '' ) );
		$bundle->set_regular_price( floatval( $hardcopy_data['Price'] ?? 0 ) );

		// Hardcopy bundles are physical products
		$bundle->set_downloadable( false );
		$bundle->set_virtual( false );

		$bundle->set_catalog_visibility( 'visible' );
		$bundle->set_status( 'publish' );

		// Set categories
		if ( !empty( $hardcopy_data['Category'] ) ) {
			$this->set_product_categories( $bundle, $hardcopy_data['Category'] );
		}

		// Attributes (match bundle creator: difficulty, ensemble-type, byline)
		$attribute_config = [
			'difficulty'    => $hardcopy_data['Difficulty'] ?? '',
			'ensemble-type' => $hardcopy_data['Ensemble Type'] ?? '',
			'byline'        => $hardcopy_data['Byline'] ?? '',
		];
		$product_importer = new RHD_CSharp_Product_Importer();
		$product_importer->set_wc_product_attributes( $bundle, $attribute_config );

		// Save early to get ID
		$bundle_id = $bundle->save();

		if ( $bundle_id ) {
			// Track base SKU and Pods meta similar to digital bundles
			$bundle->update_meta_data( '_bundle_base_sku', $base_sku );
			$bundle->save();

			$pod = pods( 'product', $bundle_id );
			$pod->save( [
				'original_url'        => $hardcopy_data['Original URL'] ?? '',
				'soundcloud_link_1'   => $hardcopy_data['Soundcloud Link'] ?? '',
				'soundcloud_link_2'   => $hardcopy_data['Soundcloud Link 2'] ?? '',
				'rhd_csharp_importer' => true,
			] );

			// Import files (images etc.) for the bundle
			$file_handler = new RHD_CSharp_File_Handler();
			$file_handler->import_product_files( $bundle, $hardcopy_data, true );

			// Build bundled items from family products (hardcopy singles)
			$product_ids = [];
			if ( isset( $family_data['products'] ) && is_array( $family_data['products'] ) ) {
				$product_ids = $family_data['products'];
			} else {
				// Fallback to all products with SKU starting with base SKU
				global $wpdb;
				$like_pattern = $wpdb->esc_like( $base_sku ) . '%';
				$product_ids  = $wpdb->get_col( $wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta}
					WHERE meta_key = '_sku'
					AND meta_value LIKE %s",
					$like_pattern
				) );
			}

			// Set bundled items
			$bundled_items = [];
			$menu_order    = 0;
			foreach ( $product_ids as $product_id ) {
				$product = wc_get_product( $product_id );
				if ( !$product ) {
					continue;
				}

				// Filter only hardcopy singles: no -D suffix
				$single_sku     = $product->get_sku();
				$sku_is_digital = is_string( $single_sku ) && preg_match( '/-D$/i', $single_sku );
				if ( $sku_is_digital ) {
					continue;
				}

				$bundled_items[] = [
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
				$menu_order++;
			}

			$bundle->set_bundled_data_items( $bundled_items );
			$bundle->save();

			// Ensure WooCommerce recognizes this as a bundle
			wp_set_object_terms( $bundle_id, 'bundle', 'product_type' );
			clean_post_cache( $bundle_id );
			wc_delete_product_transients( $bundle_id );
		}

		return $bundle_id;
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
	 * Set product attributes for grouped product
	 */
	private function set_grouped_product_attributes( $product, $family_data ) {
		$data = $family_data['full_set_data'] ?? $family_data['hardcopy_data'] ?? $family_data['base_data'] ?? [];

		$attribute_config = [
			'difficulty'    => $data['Difficulty'] ?? '',
			'ensemble-type' => $data['Ensemble Type'] ?? '',
			'instrument'    => $data['Instrumentation'] ?? '',
			'byline'        => $data['Byline'] ?? '',
		];

		$product_importer = new RHD_CSharp_Product_Importer();
		$product_importer->set_wc_product_attributes( $product, $attribute_config );
	}

	/**
	 * Set product attributes for hardcopy product
	 */
	private function set_hardcopy_product_attributes( $product, $data ) {
		$attribute_config = [
			'difficulty'    => $data['Difficulty'] ?? '',
			'ensemble-type' => $data['Ensemble Type'] ?? '',
			'instrument'    => $data['Instrumentation'] ?? '',
			'byline'        => $data['Byline'] ?? '',
		];

		$product_importer = new RHD_CSharp_Product_Importer();
		$product_importer->set_wc_product_attributes( $product, $attribute_config );
	}

	/**
	 * Update meta fields for grouped product
	 */
	private function update_grouped_meta_fields( $product_id, $family_data ) {
		$data = $family_data['full_set_data'] ?? $family_data['hardcopy_data'] ?? $family_data['base_data'] ?? [];

		$fields = [
			'original_url'        => [
				'value'    => $data['Original URL'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'soundcloud_link_1'   => [
				'value'    => $data['Soundcloud Link'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'soundcloud_link_2'   => [
				'value'    => $data['Soundcloud Link 2'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'rhd_csharp_importer' => [
				'value'    => true,
				'sanitize' => null,
			],
		];

		$update_fields = [];
		foreach ( $fields as $field_name => $field_config ) {
			$value = $field_config['value'];
			if ( $field_config['sanitize'] ) {
				$value = call_user_func( $field_config['sanitize'], $value );
			}

			$update_fields[$field_name] = $value;
		}

		$pod = pods( 'product', $product_id );
		$pod->save( $update_fields );
	}

	/**
	 * Update meta fields for hardcopy product
	 */
	private function update_hardcopy_meta_fields( $product_id, $data ) {
		$fields = [
			'original_url'        => [
				'value'    => $data['Original URL'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'soundcloud_link_1'   => [
				'value'    => $data['Soundcloud Link'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'soundcloud_link_2'   => [
				'value'    => $data['Soundcloud Link 2'] ?? '',
				'sanitize' => 'esc_url_raw',
			],
			'rhd_csharp_importer' => [
				'value'    => true,
				'sanitize' => null,
			],
		];

		$update_fields = [];
		foreach ( $fields as $field_name => $field_config ) {
			$value = $field_config['value'];
			if ( $field_config['sanitize'] ) {
				$value = call_user_func( $field_config['sanitize'], $value );
			}

			$update_fields[$field_name] = $value;
		}

		$pod = pods( 'product', $product_id );
		$pod->save( $update_fields );
	}
}
