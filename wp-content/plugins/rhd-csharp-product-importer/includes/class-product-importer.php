<?php
/**
 * Product import class
 */
class RHD_CSharp_Product_Importer {

	/**
	 * Import products from CSV file
	 */
	public function import_products_from_csv( $file_path, $update_existing = false ) {
		$csv_parser     = new RHD_CSharp_CSV_Parser();
		$bundle_creator = new RHD_CSharp_Bundle_Creator();

		$results = [
			'products_imported'        => 0,
			'products_updated'         => 0,
			'bundles_created'          => 0,
			'grouped_products_updated' => 0,
			'errors'                   => [],
		];

		// Read CSV file
		$csv_data = $csv_parser->parse( $file_path );

		if ( empty( $csv_data ) ) {
			throw new Exception( __( 'No data found in CSV file', 'rhd' ) );
		}

		$product_families  = [];
		$imported_products = [];

		// First pass: Import individual products (excluding Full Set products)
		foreach ( $csv_data as $row ) {
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

				if ( $update_existing ) {
					$pod = pods( 'product', $product_id );
					if ( $pod->field( 'rhd_csharp_importer' ) ) {
						$results['products_updated']++;
					} else {
						$results['products_imported']++;
					}

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
						$bundle_id = $bundle_creator->create_product_bundle( $base_sku, $product_families[$base_sku] );
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
	 * Process a chunk of CSV data
	 */
	public function process_chunk( $chunk_data, $update_existing = false ) {
		$results = [
			'products_imported' => 0,
			'products_updated'  => 0,
			'errors'            => [],
			'file_not_found'    => [], // Add file not found errors
		];

		// Create a single file handler instance to collect all file errors
		$file_handler = new RHD_CSharp_File_Handler();

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
				$product_id = $this->import_single_product( $row, $update_existing, $file_handler );

				if ( $product_id ) {
					$pod = pods( 'product', $product_id );
					if ( $update_existing && $pod->field( 'rhd_csharp_importer' ) ) {
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

		// Collect file not found errors from the file handler
		$file_errors = $file_handler->get_file_not_found_errors();
		foreach ( $file_errors as $file_error ) {
			$results['file_not_found'][] = sprintf(
				__( 'File not found - Product ID: %s, SKU: %s, File: %s, Type: %s', 'rhd' ),
				$file_error['product_id'],
				$file_error['sku'],
				$file_error['filename'],
				$file_error['file_type']
			);
		}

		return $results;
	}

	/**
	 * Import a single product
	 */
	public function import_single_product( $data, $update_existing = false, $file_handler = null ) {
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

		// Set product attributes
		$this->set_product_attributes( $product, $data );

		// Save the product FIRST to get a valid product ID
		try {
			$product_id = $product->save();
		} catch ( Exception $e ) {
			return false;
		}

		// Now that we have a valid product ID, handle Pods fields and file imports
		if ( $product_id ) {
			// Product Meta fields (Pods)
			$this->update_meta_fields( $product_id, $data );

			// Import and associate files - use passed file handler or create new one
			if ( !$file_handler ) {
				$file_handler = new RHD_CSharp_File_Handler();
			}
			$file_handler->import_product_files( wc_get_product( $product_id ), $data );
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
	 * Set product attributes using WooCommerce taxonomy system
	 *
	 * @param WC_Product $product          The product object to set attributes on
	 * @param array      $attribute_config Associative array of attribute_name => term_name
	 */
	public function set_wc_product_attributes( $product, $attribute_config ) {
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
	}

	/**
	 * Set product attributes from CSV data
	 */
	private function set_product_attributes( $product, $data ) {
		$attribute_config = [
			'grade'      => $data['Grade'] ?? '',
			'for-whom'   => $data['For whom'] ?? '',
			'instrument' => $data['Instrumentation'] ?? '',
		];

		$this->set_wc_product_attributes( $product, $attribute_config );
	}

	/**
	 * Update Pods fields from CSV data
	 */
	private function update_meta_fields( $product_id, $data ) {
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
