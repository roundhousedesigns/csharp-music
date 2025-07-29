<?php
/**
 * File import and management class
 */
class RHD_CSharp_File_Handler {

	/**
	 * Import and associate product files
	 */
	public function import_product_files( $product, $data ) {
		// Handle protected digital file
		if ( !empty( $data['Product File Name'] ) ) {
			$this->import_protected_file( $product, $data['Product File Name'] );
		}

		// Handle product image
		if ( !empty( $data['Image File Name'] ) ) {
			$this->import_product_image( $product->get_id(), $data['Image File Name'] );
		}

		// Handle sound files
		$sound_files_field = 'Sound Filenames (comma-separated)\nExample: f-warmup.mp3, g-warmup.wav';
		if ( !empty( $data[$sound_files_field] ) ) {
			$this->import_sound_files( $product->get_id(), $data[$sound_files_field] );
		}
	}

	/**
	 * Import protected digital file for WooCommerce download
	 */
	public function import_protected_file( $product, $filename ) {
		if ( empty( $filename ) ) {
			return false;
		}

		// Find the actual file path using flexible matching
		$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/protected-files', $filename );

		if ( !$source_path ) {
			error_log( "Protected file not found: {$filename}" );
			return false;
		}

		$sanitized_filename = $this->sanitize_filename( $filename );

		$upload_dir = wp_upload_dir();
		$wc_uploads = $upload_dir['basedir'] . '/woocommerce_uploads';

		// Create WooCommerce uploads directory if it doesn't exist
		if ( !file_exists( $wc_uploads ) ) {
			wp_mkdir_p( $wc_uploads );
			// Add .htaccess protection
			file_put_contents( $wc_uploads . '/.htaccess', "deny from all\n" );
		}

		// Create unique filename to prevent conflicts (using sanitized name)
		$unique_filename  = wp_unique_filename( $wc_uploads, $sanitized_filename );
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
			$product->set_virtual( true );

			error_log( "Protected file imported: {$filename} -> {$unique_filename}" );
			return true;
		}

		error_log( "Failed to copy protected file: {$source_path} -> {$destination_path}" );
		return false;
	}

	/**
	 * Import product image to Media Library and set as featured image
	 */
	public function import_product_image( $product_id, $filename ) {
		if ( empty( $filename ) ) {
			return false;
		}

		// Check if product already has a featured image set - if so, skip
		$existing_featured_image = get_post_thumbnail_id( $product_id );
		if ( $existing_featured_image ) {
			return $existing_featured_image;
		}

		// Find the actual file path using flexible matching
		$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/images', $filename );

		if ( !$source_path ) {
			error_log( "Image file not found: {$filename}" );
			return false;
		}

		$sanitized_filename = $this->sanitize_filename( $filename );

		// Check if image already exists in Media Library (by sanitized filename)
		$existing_attachment = $this->get_attachment_by_filename( $sanitized_filename );
		if ( $existing_attachment ) {
			set_post_thumbnail( $product_id, $existing_attachment );
			error_log( "Using existing image: {$sanitized_filename}" );
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

		error_log( "Failed to import image: {$source_path} -> {$destination_path}" );
		return false;
	}

	/**
	 * Import sound files to Media Library
	 */
	public function import_sound_files( $product_id, $filenames_string ) {
		if ( empty( $filenames_string ) ) {
			return [];
		}

		// Parse comma-separated filenames
		$filenames      = array_map( 'trim', explode( ',', $filenames_string ) );
		$sound_file_ids = [];

		foreach ( $filenames as $filename ) {
			if ( empty( $filename ) ) {
				continue;
			}

			// Find the actual file path using flexible matching
			$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/sounds', $filename );

			if ( !$source_path ) {
				error_log( "Sound file not found: {$filename}" );
				continue;
			}

			$sanitized_filename = $this->sanitize_filename( $filename );

			// Check if file already exists (by sanitized filename)
			$existing_attachment = $this->get_attachment_by_filename( $sanitized_filename );
			if ( $existing_attachment ) {
				$sound_file_ids[] = $existing_attachment;
				error_log( "Using existing sound file: {$sanitized_filename}" );
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
					error_log( "Sound file imported: {$filename} -> {$unique_filename}" );
				}
			} else {
				error_log( "Failed to copy sound file: {$source_path} -> {$destination_path}" );
			}
		}

		// Store sound file IDs as Pods field or meta
		if ( !empty( $sound_file_ids ) ) {
			$pod = pods( 'product', $product_id );
			$pod->save( 'sound_files', $sound_file_ids );

			error_log( 'Stored ' . count( $sound_file_ids ) . " sound file IDs for product {$product_id}" );
		}

		return $sound_file_ids;
	}

	/**
	 * Sanitize filename by converting spaces to hyphens and removing unsafe characters
	 */
	public function sanitize_filename( $filename ) {
		// Get file extension
		$pathinfo  = pathinfo( $filename );
		$name      = $pathinfo['filename'];
		$extension = isset( $pathinfo['extension'] ) ? '.' . $pathinfo['extension'] : '';

		// Convert spaces to hyphens and remove unsafe characters
		$name = preg_replace( '/\s+/', '-', $name ); // Convert spaces to hyphens
		$name = preg_replace( '/[^a-zA-Z0-9\-_.]/', '', $name ); // Remove unsafe characters
		$name = preg_replace( '/-+/', '-', $name ); // Convert multiple hyphens to single
		$name = trim( $name, '-' ); // Remove leading/trailing hyphens

		return $name . $extension;
	}

	/**
	 * Find file in directory, trying multiple filename variations
	 */
	public function find_file_in_directory( $directory, $filename ) {
		$possible_filenames = [
			$filename, // Original filename from CSV
			$this->sanitize_filename( $filename ), // Sanitized version
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

		error_log( "File not found with any variation: {$filename} in {$directory}" );
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
			$sanitized_filename = $this->sanitize_filename( $filename );
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
