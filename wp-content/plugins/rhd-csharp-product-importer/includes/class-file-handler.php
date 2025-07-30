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
		// Handle bundle products differently
		if ( is_a( $product, 'WC_Product_Bundle' ) ) {
			$this->import_bundle_files( $product, $data );
		} else {
			// Handle protected digital file for individual products
			if ( !empty( $data['Product File Name'] ) ) {
				$this->import_protected_file( $product, $data['Product File Name'] );
			}
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

		// Check if product already has this download to prevent duplicates
		$existing_downloads = $product->get_downloads();
		foreach ( $existing_downloads as $download ) {
			if ( $download->get_name() === $filename ) {
				// File already exists as download, skip processing
				return true;
			}
		}

		// Find the actual file path using new directory structure
		$source_path = $this->find_file_in_protected_folders( $filename, $product->get_sku() );

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
	 * Import files for bundle products - creates ZIP from project folder
	 */
	public function import_bundle_files( $product, $data ) {
		try {
			$base_sku = $this->get_base_sku_from_bundle( $product );
			
			if ( !$base_sku ) {
				error_log( 'RHD Import: No base SKU found for bundle ' . $product->get_id() );
				return false;
			}

			// Find the project folder matching this base SKU
			$project_folder = $this->find_project_folder( $base_sku );
			
			if ( !$project_folder ) {
				$this->add_file_not_found_error( $product->get_id(), $product->get_sku(), $base_sku . ' project folder', 'bundle' );
				error_log( 'RHD Import: Project folder not found for base SKU: ' . $base_sku );
				return false;
			}

			// Always set up bundle as downloadable, but create ZIP on-demand
			$this->setup_bundle_download( $product, $base_sku, $project_folder );

			return true;
		} catch ( Exception $e ) {
			error_log( 'RHD Import: Exception in import_bundle_files: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Set up bundle download with on-demand ZIP creation
	 */
	private function setup_bundle_download( $product, $base_sku, $project_folder ) {
		// Store project folder path as meta for later ZIP creation
		$product->update_meta_data( '_rhd_project_folder', $project_folder );
		$product->update_meta_data( '_rhd_base_sku', $base_sku );

		// Set up the download with a placeholder that will be resolved on access
		$downloads = $product->get_downloads();
		$download_id = md5( $base_sku . '-bundle' );

		$downloads[$download_id] = new WC_Product_Download();
		$downloads[$download_id]->set_id( $download_id );
		$downloads[$download_id]->set_name( $base_sku . ' - Full Set' );
		
		// Use a special file path that indicates lazy loading
		$downloads[$download_id]->set_file( 'rhd_lazy:' . $base_sku );

		$product->set_downloads( $downloads );
		$product->set_downloadable( true );
		$product->save();
	}

	/**
	 * Create ZIP file on-demand for bundle download
	 */
	public function create_bundle_zip_on_demand( $base_sku ) {
		// Find the project folder
		$project_folder = $this->find_project_folder( $base_sku );
		
		if ( !$project_folder ) {
			error_log( 'RHD Import: Project folder not found for on-demand ZIP creation: ' . $base_sku );
			return false;
		}

		// Create or get existing ZIP
		$zip_path = $this->get_or_create_bundle_zip( $base_sku, $project_folder );
		
		if ( !$zip_path ) {
			error_log( 'RHD Import: Failed to create on-demand ZIP for: ' . $base_sku );
			return false;
		}

		return $zip_path;
	}

	/**
	 * Find project folder matching base SKU
	 */
	private function find_project_folder( $base_sku ) {
		try {
			$protected_files_dir = WP_CONTENT_DIR . '/csharp-import/protected-files';
			
			if ( !is_dir( $protected_files_dir ) ) {
				error_log( 'RHD Import: Protected files directory does not exist: ' . $protected_files_dir );
				return false;
			}

			$folders = scandir( $protected_files_dir );
			
			if ( $folders === false ) {
				error_log( 'RHD Import: Could not scan protected files directory: ' . $protected_files_dir );
				return false;
			}
			
			foreach ( $folders as $folder ) {
				if ( $folder === '.' || $folder === '..' ) {
					continue;
				}
				
				$folder_path = $protected_files_dir . '/' . $folder;
				
				if ( is_dir( $folder_path ) && strpos( $folder, $base_sku ) === 0 ) {
					return $folder_path;
				}
			}
			
			return false;
		} catch ( Exception $e ) {
			error_log( 'RHD Import: Exception in find_project_folder: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get existing ZIP file or create new one from project folder
	 */
	private function get_or_create_bundle_zip( $base_sku, $project_folder_path ) {
		try {
			$upload_dir = wp_upload_dir();
			$wc_uploads = $upload_dir['basedir'] . '/woocommerce_uploads';
			
			// Ensure woocommerce_uploads directory exists
			if ( !file_exists( $wc_uploads ) ) {
				if ( !wp_mkdir_p( $wc_uploads ) ) {
					error_log( 'RHD Import: Failed to create woocommerce_uploads directory: ' . $wc_uploads );
					return false;
				}
			}
			
			// Create bundles subdirectory for organization
			$bundles_dir = $wc_uploads . '/bundles';
			if ( !file_exists( $bundles_dir ) ) {
				if ( !wp_mkdir_p( $bundles_dir ) ) {
					error_log( 'RHD Import: Failed to create bundles directory: ' . $bundles_dir );
					return false;
				}
			}
			
			$zip_filename = $base_sku . '-full-set.zip';
			$zip_path = $bundles_dir . '/' . $zip_filename;
			
			// Check if ZIP already exists
			if ( file_exists( $zip_path ) ) {
				return $zip_path;
			}
			
			// Create new ZIP file
			return $this->create_zip_from_folder( $project_folder_path, $zip_path );
		} catch ( Exception $e ) {
			error_log( 'RHD Import: Exception in get_or_create_bundle_zip: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Create ZIP file from folder contents
	 */
	private function create_zip_from_folder( $source_folder, $zip_path ) {
		try {
			// Check if ZipArchive class exists
			if ( !class_exists( 'ZipArchive' ) ) {
				error_log( 'RHD Import: ZipArchive class not available. PHP zip extension may not be installed.' );
				return false;
			}

			// Validate source folder
			if ( !is_dir( $source_folder ) ) {
				error_log( 'RHD Import: Source folder does not exist: ' . $source_folder );
				return false;
			}

			$zip = new ZipArchive();
			$result = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
			
			if ( $result !== TRUE ) {
				error_log( 'RHD Import: Failed to create ZIP archive. Error code: ' . $result . ', Path: ' . $zip_path );
				return false;
			}

			// Check if we can create RecursiveDirectoryIterator
			try {
				$files = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $source_folder ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( Exception $e ) {
				error_log( 'RHD Import: Failed to create recursive directory iterator: ' . $e->getMessage() );
				$zip->close();
				return false;
			}

			$file_count = 0;
			foreach ( $files as $name => $file ) {
				if ( !$file->isDir() ) {
					$file_path = $file->getRealPath();
					$relative_path = substr( $file_path, strlen( $source_folder ) + 1 );
					
					if ( $zip->addFile( $file_path, $relative_path ) ) {
						$file_count++;
					} else {
						error_log( 'RHD Import: Failed to add file to ZIP: ' . $file_path );
					}
				}
			}

			$zip_result = $zip->close();
			
			if ( !$zip_result ) {
				error_log( 'RHD Import: Failed to close ZIP archive: ' . $zip_path );
				return false;
			}

			if ( $file_count === 0 ) {
				error_log( 'RHD Import: No files were added to ZIP archive: ' . $zip_path );
				return false;
			}

			return file_exists( $zip_path ) ? $zip_path : false;
		} catch ( Exception $e ) {
			error_log( 'RHD Import: Exception in create_zip_from_folder: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get base SKU from bundle product
	 */
	private function get_base_sku_from_bundle( $product ) {
		// Try to get from bundle meta first
		$base_sku = $product->get_meta( '_bundle_base_sku' );
		
		if ( $base_sku ) {
			return $base_sku;
		}
		
		// Fallback: extract from product SKU
		$sku = $product->get_sku();
		if ( $sku ) {
			return $this->get_base_sku( $sku );
		}
		
		return false;
	}

	/**
	 * Get base SKU from full SKU (extract first two parts)
	 */
	private function get_base_sku( $sku ) {
		$parts = explode( '-', $sku );
		if ( count( $parts ) >= 2 ) {
			return $parts[0] . '-' . $parts[1];
		}
		return $sku;
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
	 * Find file in protected folders using new directory structure
	 */
	private function find_file_in_protected_folders( $filename, $product_sku ) {
		$base_sku = $this->get_base_sku( $product_sku );
		
		// First try to find the project folder for this SKU
		$project_folder = $this->find_project_folder( $base_sku );
		
		if ( $project_folder ) {
			// Search within the project folder
			$file_path = $this->find_file_in_directory( $project_folder, $filename );
			if ( $file_path ) {
				return $file_path;
			}
		}
		
		// Fallback: search in the old flat structure for backwards compatibility
		$protected_files_dir = WP_CONTENT_DIR . '/csharp-import/protected-files';
		return $this->find_file_in_directory( $protected_files_dir, $filename );
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
