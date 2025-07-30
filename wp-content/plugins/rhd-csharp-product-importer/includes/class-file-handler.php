<?php
/**
 * File import and management class
 */
class RHD_CSharp_File_Handler {

	/**
	 * Array to collect file not found errors
	 */
	private $file_not_found_errors = [];

	/**
	 * Get collected file not found errors
	 */
	public function get_file_not_found_errors() {
		return $this->file_not_found_errors;
	}

	/**
	 * Clear file not found errors
	 */
	public function clear_file_not_found_errors() {
		$this->file_not_found_errors = [];
	}

	/**
	 * Add a file not found error
	 */
	private function add_file_not_found_error( $product_id, $sku, $filename, $file_type ) {
		$this->file_not_found_errors[] = [
			'product_id' => $product_id,
			'sku'        => $sku,
			'filename'   => $filename,
			'file_type'  => $file_type,
		];
	}

	/**
	 * Import and associate product files
	 */
	public function import_product_files( $product, $data ) {
		// Handle protected digital file, as long as this isn't a bundle
		if ( !empty( $data['Product File Name'] ) && !is_a( $product, 'WC_Product_Bundle' ) ) {
			$this->import_protected_file( $product, $data['Product File Name'] );
		}

		// Handle product image
		if ( !empty( $data['Image File Name'] ) ) {
			$this->import_product_image( $product->get_id(), $data['Image File Name'], $product->get_sku() );
		}

		// Handle sound files
		$sound_files_field = 'Sound Filenames (comma-separated)';
		if ( !empty( $data[$sound_files_field] ) ) {
			$this->import_sound_files( $product->get_id(), $data[$sound_files_field], $product->get_sku() );
		}
	}

	/**
	 * Import protected digital file for WooCommerce download
	 */
	public function import_protected_file( $product, $filename ) {
		if ( empty( $filename ) ) {
			return false;
		}

		// If the file doesn't have an extension, assume .pdf
		$filename = preg_match( '/\.\w{3,4}$/', $filename ) ? $filename : $filename . '.pdf';

		// Find the actual file path using flexible matching
		$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/protected-files', $filename );

		if ( !$source_path ) {
			$this->add_file_not_found_error( $product->get_id(), $product->get_sku(), $filename, 'product' );
			return false;
		}

		$upload_dir = wp_upload_dir();
		$wc_uploads = $upload_dir['basedir'] . '/woocommerce_uploads';

		// Create unique filename to prevent conflicts
		$unique_filename  = wp_unique_filename( $wc_uploads, $filename );
		$destination_path = $wc_uploads . '/' . $unique_filename;

		// Copy file to protected location
		if ( copy( $source_path, $destination_path ) ) {
			// Set up downloadable file for WooCommerce
			$downloads   = $product->get_downloads();
			$download_id = md5( $unique_filename );

			$downloads[$download_id] = new WC_Product_Download();
			$downloads[$download_id]->set_id( $download_id );
			$downloads[$download_id]->set_name( $filename ); // Keep original name for display
			$downloads[$download_id]->set_file( $destination_path );

			$product->set_downloads( $downloads );
			$product->set_downloadable( true );
			$product->save();

			return true;
		}

		return false;
	}

	/**
	 * Import product image to Media Library and set as featured image
	 */
	public function import_product_image( $product_id, $filename, $sku = '' ) {
		if ( empty( $filename ) ) {
			return false;
		}

		// If the file doesn't have an extension, assume .jpg
		$filename = preg_match( '/\.\w{3,4}$/', $filename ) ? $filename : $filename . '.jpg';

		// Check if product already has a featured image set - if so, skip
		$existing_featured_image = get_post_thumbnail_id( $product_id );
		if ( $existing_featured_image ) {
			return $existing_featured_image;
		}

		// Find the actual file path using flexible matching
		$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/images', $filename );

		if ( !$source_path ) {
			$this->add_file_not_found_error( $product_id, $sku, $filename, 'image' );
			return false;
		}

		// Even though sanitize_file_name() is used in wp_unique_filename(), we're using it to check if the file already exists in the Media Library
		$sanitized_filename = sanitize_file_name( $filename );

		// Check if image already exists in Media Library (by sanitized filename)
		$existing_attachment = $this->get_attachment_by_filename( $sanitized_filename );
		if ( $existing_attachment ) {
			set_post_thumbnail( $product_id, $existing_attachment );
			return $existing_attachment;
		}

		// Upload to Media Library
		$upload_dir       = wp_upload_dir();
		$unique_filename  = wp_unique_filename( $upload_dir['path'], $sanitized_filename );
		$destination_path = $upload_dir['path'] . '/' . $unique_filename;

		if ( copy( $source_path, $destination_path ) ) {
			// Create attachment
			$attachment = [
				'guid'           => $upload_dir['url'] . '/' . $unique_filename,
				'post_mime_type' => wp_check_filetype( $unique_filename )['type'],
				'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ), // Use original name for title
				'post_content'   => '',
				'post_status'    => 'inherit',
			];

			$attachment_id = wp_insert_attachment( $attachment, $destination_path );

			if ( !is_wp_error( $attachment_id ) ) {
				// Generate metadata
				require_once ABSPATH . 'wp-admin/includes/image.php';
				$attachment_data = wp_generate_attachment_metadata( $attachment_id, $destination_path );
				wp_update_attachment_metadata( $attachment_id, $attachment_data );

				// Set as featured image
				set_post_thumbnail( $product_id, $attachment_id );

				return $attachment_id;
			}
		}

		return false;
	}

	/**
	 * Import sound files to Media Library
	 */
	public function import_sound_files( $product_id, $filenames_string, $sku = '' ) {
		if ( empty( $filenames_string ) ) {
			return [];
		}

		// Parse semicolon-separated filenames
		$filenames      = array_map( 'trim', explode( ';', $filenames_string ) );
		$sound_file_ids = [];

		foreach ( $filenames as $filename ) {
			if ( empty( $filename ) ) {
				continue;
			}

			// If the file doesn't have an extension, assume .mp3
			$filename = preg_match( '/\.\w{3,4}$/', $filename ) ? $filename : $filename . '.mp3';

			// Find the actual file path using flexible matching
			$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/sounds', $filename );

			if ( !$source_path ) {
				$this->add_file_not_found_error( $product_id, $sku, $filename, 'sound' );
				continue;
			}

			// Even though sanitize_file_name() is used in wp_unique_filename(), we're using it to check if the file already exists in the Media Library
			$sanitized_filename = sanitize_file_name( $filename );

			// Check if file already exists (by sanitized filename)
			$existing_attachment = $this->get_attachment_by_filename( $sanitized_filename );
			if ( $existing_attachment ) {
				$sound_file_ids[] = $existing_attachment;
				continue;
			}

			// Upload to Media Library
			$upload_dir       = wp_upload_dir();
			$unique_filename  = wp_unique_filename( $upload_dir['path'], $sanitized_filename );
			$destination_path = $upload_dir['path'] . '/' . $unique_filename;

			if ( copy( $source_path, $destination_path ) ) {
				$attachment = [
					'guid'           => $upload_dir['url'] . '/' . $unique_filename,
					'post_mime_type' => wp_check_filetype( $unique_filename )['type'],
					'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ), // Use original name for title
					'post_content'   => '',
					'post_status'    => 'inherit',
				];

				$attachment_id = wp_insert_attachment( $attachment, $destination_path );

				if ( !is_wp_error( $attachment_id ) ) {
					$sound_file_ids[] = $attachment_id;
				}
			}
		}

		// Store sound file IDs as Pods field
		if ( !empty( $sound_file_ids ) ) {
			$pod = pods( 'product', $product_id );
			$pod->save( ['audio_files' => $sound_file_ids] );
		}

		return $sound_file_ids;
	}

	/**
	 * Find file in directory, trying multiple filename variations
	 */
	public function find_file_in_directory( $directory, $filename ) {
		$possible_filenames = [
			$filename, // Original filename from CSV
			sanitize_file_name( $filename ), // Sanitized version
			str_replace( ' ', '-', $filename ), // Simple space-to-hyphen conversion
			str_replace( ' ', '_', $filename ), // Space-to-underscore conversion
			str_replace( ' ', '', $filename ), // Remove spaces entirely
		];

		foreach ( $possible_filenames as $possible_filename ) {
			$full_path = rtrim( $directory, '/' ) . '/' . $possible_filename;
			if ( file_exists( $full_path ) ) {
				return $full_path;
			}
		}

		// If still not found, try case-insensitive search
		if ( is_dir( $directory ) ) {
			$files = scandir( $directory );
			foreach ( $files as $file ) {
				if ( '.' === $file || '..' === $file ) {
					continue;
				}

				// Try case-insensitive comparison with all possible variations
				foreach ( $possible_filenames as $possible_filename ) {
					if ( strcasecmp( $file, $possible_filename ) === 0 ) {
						$full_path = rtrim( $directory, '/' ) . '/' . $file;
						return $full_path;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Get attachment ID by filename (checks both original and sanitized versions)
	 */
	private function get_attachment_by_filename( $filename ) {
		global $wpdb;

		// First try the exact filename
		$attachment_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			WHERE meta_key = '_wp_attached_file'
			AND meta_value LIKE %s",
			'%' . $wpdb->esc_like( $filename )
		) );

		// If not found, try the sanitized version
		if ( !$attachment_id ) {
			$sanitized_filename = sanitize_file_name( $filename );
			$attachment_id      = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				WHERE meta_key = '_wp_attached_file'
				AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $sanitized_filename )
			) );
		}

		return $attachment_id ? intval( $attachment_id ) : false;
	}
}
