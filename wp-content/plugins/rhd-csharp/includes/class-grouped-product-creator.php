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
			error_log( 'RHD Import: Updating existing grouped product ID: ' . $existing_grouped_id );
			return $this->update_existing_grouped_product( $existing_grouped_id, $base_sku, $family_data, $bundle_id );
		}

		error_log( 'RHD Import: Creating new grouped product for base SKU: ' . $base_sku );
		$result = $this->create_new_grouped_product( $base_sku, $family_data, $bundle_id );

		error_log( 'RHD Import: New grouped product ID: ' . $result );

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
			error_log( 'RHD Import: Converting existing product ' . $existing_product_id . ' to grouped product for SKU: ' . $base_sku );
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

		// Check if hardcopy product already exists
		$existing_id = wc_get_product_id_by_sku( $hardcopy_sku );
		if ( $existing_id ) {
			return $existing_id;
		}

		// Create new hardcopy product
		$product = new WC_Product_Simple();
		$product->set_name( $hardcopy_data['Product Title'] ?? '' );
		$product->set_sku( $hardcopy_sku );
		$product->set_description( wp_kses_post( $hardcopy_data['Description'] ?? '' ) );
		$product->set_regular_price( floatval( $hardcopy_data['Price'] ?? 0 ) );
		$product->set_catalog_visibility( 'visible' );
		$product->set_status( 'publish' );

		// Set as physical product (not downloadable)
		$product->set_downloadable( false );
		$product->set_virtual( false );

		// Set categories
		if ( !empty( $hardcopy_data['Category'] ) ) {
			$this->set_product_categories( $product, $hardcopy_data['Category'] );
		}

		// Set product attributes
		$this->set_hardcopy_product_attributes( $product, $hardcopy_data );

		$product_id = $product->save();

		if ( $product_id ) {
			// Update meta fields
			$this->update_hardcopy_meta_fields( $product_id, $hardcopy_data );

			// Import files if file handler is available
			$file_handler = new RHD_CSharp_File_Handler();
			$file_handler->import_product_files( wc_get_product( $product_id ), $hardcopy_data, true );
		}

		return $product_id;
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
