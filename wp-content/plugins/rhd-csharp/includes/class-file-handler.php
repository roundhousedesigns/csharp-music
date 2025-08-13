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
	 * Import log file path
	 */
	public $log_file_path = null;

	/**
	 * Set the log file path for existing log files
	 */
	public function set_log_file_path( $log_file_path ) {
		$this->log_file_path = $log_file_path;
	}

	/**
	 * Initialize import logging
	 */
	public function init_import_log() {
		$logs_dir = WP_CONTENT_DIR . '/rhd-import-logs';

		// Create logs directory if it doesn't exist
		if ( !file_exists( $logs_dir ) ) {
			wp_mkdir_p( $logs_dir );
		}

		// Create log file with timestamp
		$timestamp           = date( 'Y-m-d_H-i-s' );
		$this->log_file_path = $logs_dir . '/import-log-' . $timestamp . '.txt';

		// Write log header
		$this->write_to_log( '=== RHD C# Import Log Started ===' );
		$this->write_to_log( 'Date: ' . date( 'Y-m-d H:i:s' ) );
		$this->write_to_log( 'WordPress Version: ' . get_bloginfo( 'version' ) );
		$this->write_to_log( 'Plugin Version: ' . RHD_CSHARP_IMPORTER_VERSION );
		$this->write_to_log( "=====================================\n" );

		return $this->log_file_path;
	}

	/**
	 * Write message to import log
	 */
	public function write_to_log( $message, $level = 'INFO' ) {
		if ( !$this->log_file_path ) {
			return false;
		}

		$timestamp         = date( 'Y-m-d H:i:s' );
		$formatted_message = "[{$timestamp}] [{$level}] {$message}\n";

		file_put_contents( $this->log_file_path, $formatted_message, FILE_APPEND | LOCK_EX );
		return true;
	}

	/**
	 * Log import phase start
	 */
	public function log_phase_start( $phase_name, $total_items = null ) {
		$message = "=== Starting Phase: {$phase_name} ===";
		if ( null !== $total_items ) {
			$message .= " (Processing {$total_items} items)";
		}
		$this->write_to_log( $message );
	}

	/**
	 * Log import phase completion
	 */
	public function log_phase_complete( $phase_name, $results = [] ) {
		$this->write_to_log( "=== Completed Phase: {$phase_name} ===" );
		foreach ( $results as $key => $value ) {
			$formatted_value = $this->format_log_value( $value );
			$this->write_to_log( "  {$key}: {$formatted_value}" );
		}
		$this->write_to_log( '' ); // Empty line for readability
	}

	/**
	 * Format values for logging (handles arrays, objects, etc.)
	 */
	private function format_log_value( $value ) {
		if ( is_array( $value ) ) {
			if ( empty( $value ) ) {
				return '[]';
			}

			// For small arrays, show inline. For larger arrays, show formatted.
			if ( count( $value ) <= 3 ) {
				return '[' . implode( ', ', array_map( function ( $item ) {
					return is_string( $item ) ? '"' . $item . '"' : (string) $item;
				}, $value ) ) . ']';
			} else {
				return "[\n" . implode( "\n", array_map( function ( $item ) {
					$formatted_item = is_string( $item ) ? '"' . $item . '"' : (string) $item;
					return "    - {$formatted_item}";
				}, $value ) ) . "\n  ]";
			}
		} elseif ( is_object( $value ) ) {
			return 'Object(' . get_class( $value ) . ')';
		} elseif ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		} elseif ( is_null( $value ) ) {
			return 'null';
		} else {
			return (string) $value;
		}
	}

	/**
	 * Log import success
	 */
	public function log_import_success( $message, $details = [] ) {
		$this->write_to_log( $message, 'SUCCESS' );
		foreach ( $details as $key => $value ) {
			$formatted_value = $this->format_log_value( $value );
			$this->write_to_log( "  {$key}: {$formatted_value}", 'SUCCESS' );
		}
	}

	/**
	 * Generate final import summary in log
	 */
	public function log_import_summary( $summary_data ) {
		$this->write_to_log( "\n=== IMPORT SUMMARY ===" );
		$this->write_to_log( 'Products Imported: ' . ( $summary_data['products_imported'] ?? 0 ) );
		$this->write_to_log( 'Products Updated: ' . ( $summary_data['products_updated'] ?? 0 ) );
		$this->write_to_log( 'Bundles Created: ' . ( $summary_data['bundles_created'] ?? 0 ) );

		// File error summary
		$file_errors = $this->get_file_not_found_errors();
		if ( !empty( $file_errors ) ) {
			$this->write_to_log( "\nFile Errors by Type:" );

			$error_counts = [
				'product' => 0,
				'image'   => 0,
				'sound'   => 0,
				'bundle'  => 0,
			];

			foreach ( $file_errors as $error ) {
				if ( isset( $error_counts[$error['file_type']] ) ) {
					$error_counts[$error['file_type']]++;
				}
			}

			foreach ( $error_counts as $type => $count ) {
				if ( $count > 0 ) {
					$this->write_to_log( "  {$type} files not found: {$count}" );
				}
			}

			$this->write_to_log( '  Total files not found: ' . count( $file_errors ) );

			// Log detailed file errors if any
			if ( count( $file_errors ) > 0 ) {
				$this->write_to_log( "\nDetailed File Errors:" );
				foreach ( $file_errors as $error ) {
					$this->write_to_log( "  - SKU: {$error['sku']}, File: {$error['filename']}, Type: {$error['file_type']}" );
				}
			}
		}

		// General errors
		if ( isset( $summary_data['errors'] ) && !empty( $summary_data['errors'] ) ) {
			$this->write_to_log( "\nGeneral Errors: " . count( $summary_data['errors'] ) );
			foreach ( $summary_data['errors'] as $error ) {
				$this->write_to_log( "  - {$error}", 'ERROR' );
			}
		}

		$this->write_to_log( "\n=== END IMPORT SUMMARY ===" );
		$this->write_to_log( 'Log file location: ' . $this->log_file_path );
	}

	/**
	 * Log file not found error with detailed info
	 */
	public function log_file_not_found( $product_id, $sku, $filename, $file_type, $search_path = '' ) {
		// Always add to the error collection
		$this->add_file_not_found_error( $product_id, $sku, $filename, $file_type );

		$message = "File not found - Product ID: {$product_id}, SKU: {$sku}, File: {$filename}, Type: {$file_type}";
		if ( $search_path ) {
			$message .= ", Search Path: {$search_path}";
		}

		// Log to file if available, otherwise log to error_log for debugging
		if ( $this->log_file_path && file_exists( dirname( $this->log_file_path ) ) ) {
			$this->write_to_log( $message, 'ERROR' );
		} else {
			// Fallback to WordPress error log
			error_log( "RHD Import File Error: {$message}" );
		}
	}

	/**
	 * Log general import error
	 */
	public function log_import_error( $error_message, $context = '' ) {
		$message = "Import Error: {$error_message}";
		if ( $context ) {
			$message .= " (Context: {$context})";
		}
		$this->write_to_log( $message, 'ERROR' );
	}

	/**
	 * Get current log file path
	 */
	public function get_log_file_path() {
		return $this->log_file_path;
	}

	/**
	 * Get log filename only (without path)
	 */
	public function get_log_filename() {
		return $this->log_file_path ? basename( $this->log_file_path ) : null;
	}

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
	public function import_product_files( $product, $data, $update_existing = false ) {
		// Attach the protected digital file for ALL products (bundles and simple)
		if ( !empty( $data['Product File Name'] ) ) {
			$this->import_protected_file( $product, $data['Product File Name'] );
		}

		// Handle product image for ALL products (including bundles)
		if ( !empty( $data['Image File Name'] ) ) {
			$this->import_product_image( $product->get_id(), $data['Image File Name'], $product->get_sku(), $update_existing );
		}

		// Handle sound files for ALL products (including bundles)
		$sound_files_field = 'Sound Filenames (semicolon-separated)';
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
			$this->log_file_not_found( $product->get_id(), $product->get_sku(), $filename, 'product' );
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
	public function import_product_image( $product_id, $filename, $sku = '', $update_existing = false ) {
		if ( empty( $filename ) ) {
			return false;
		}

		// If the file doesn't have an extension, assume .jpg
		$filename = preg_match( '/\.\w{3,4}$/', $filename ) ? $filename : $filename . '.jpg';

		// Check if product already has a featured image set - only skip if we're not updating existing
		$existing_featured_image = get_post_thumbnail_id( $product_id );
		if ( $existing_featured_image && !$update_existing ) {
			return $existing_featured_image;
		}

		// Find the actual file path using flexible matching
		$source_path = $this->find_file_in_directory( WP_CONTENT_DIR . '/csharp-import/Cover Images', $filename );

		if ( !$source_path ) {
			$this->log_file_not_found( $product_id, $sku, $filename, 'image' );
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
			$this->write_to_log( 'No sound filenames provided; skipping.', 'DEBUG' ) || error_log( 'RHD Import Debug: No sound filenames provided; skipping.' );
			return [];
		}

		// Hint if delimiter seems to be commas instead of semicolons
		if ( strpos( $filenames_string, ',' ) !== false && strpos( $filenames_string, ';' ) === false ) {
			$this->write_to_log( 'Sound filenames string appears comma-separated; importer expects semicolons. Raw: ' . $filenames_string, 'DEBUG' ) || error_log( 'RHD Import Debug: Sound filenames string appears comma-separated; importer expects semicolons. Raw: ' . $filenames_string );
		}

		// Parse semicolon-separated filenames
		$filenames = array_map( 'trim', explode( ';', $filenames_string ) );

		$sound_files_dir = WP_CONTENT_DIR . '/csharp-import/Sound Files';

		$sound_file_ids = [];
		$stats          = [
			'requested' => 0,
			'found'     => 0,
			'reused'    => 0,
			'uploaded'  => 0,
			'missing'   => 0,
		];

		foreach ( $filenames as $filename ) {
			if ( empty( $filename ) ) {
				continue;
			}
			$stats['requested']++;

			$original_filename = $filename;
			// If the file doesn't have an extension, assume .mp3
			$filename = preg_match( '/\.\w{3,4}$/', $filename ) ? $filename : $filename . '.mp3';

			// Find the actual file path using flexible matching
			$source_path = $this->find_file_in_directory( $sound_files_dir, $filename );

			if ( !$source_path ) {
				$stats['missing']++;
				$this->write_to_log( "Source file not found for '{$filename}' in '{$sound_files_dir}'", 'DEBUG' ) || error_log( "RHD Import Debug: Source file not found for '{$filename}' in '{$sound_files_dir}'" );
				$this->log_file_not_found( $product_id, $sku, $filename, 'sound' );
				continue;
			}

			// Even though sanitize_file_name() is used in wp_unique_filename(), we're using it to check if the file already exists in the Media Library
			$sanitized_filename = sanitize_file_name( $filename );

			// Check if file already exists (by sanitized filename)
			$existing_attachment = $this->get_attachment_by_filename( $sanitized_filename );
			if ( $existing_attachment ) {
				$stats['found']++;
				$stats['reused']++;
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
					'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
					'post_content'   => '',
					'post_status'    => 'inherit',
				];

				$attachment_id = wp_insert_attachment( $attachment, $destination_path );

				if ( is_wp_error( $attachment_id ) ) {
					$this->log_import_error( 'wp_insert_attachment failed for sound file: ' . $unique_filename . ' | Error: ' . $attachment_id->get_error_message(), 'Sound Files' );
				} else {
					$stats['uploaded']++;
					$sound_file_ids[] = $attachment_id;
				}
			} else {
				$this->log_import_error( 'Failed to copy sound file from ' . $source_path . ' to ' . $destination_path, 'Sound Files' );
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
				$this->log_import_error( 'No base SKU found for bundle ' . $product->get_id() );
				return false;
			}

			// Find the project folder matching this base SKU
			$project_folder = $this->find_project_folder( $base_sku );

			if ( !$project_folder ) {
				$this->log_file_not_found( $product->get_id(), $product->get_sku(), $base_sku . ' project folder', 'bundle' );
				$this->log_import_error( 'Project folder not found for base SKU: ' . $base_sku );
				return false;
			}

			// Always set up bundle as downloadable, but create ZIP on-demand
			$this->setup_bundle_download( $product, $base_sku, $project_folder );

			return true;
		} catch ( Exception $e ) {
			$this->log_import_error( 'Exception in import_bundle_files: ' . $e->getMessage() );
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
		$downloads   = $product->get_downloads();
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
			$this->log_import_error( 'Project folder not found for on-demand ZIP creation: ' . $base_sku );
			return false;
		}

		// Create or get existing ZIP
		$zip_path = $this->get_or_create_bundle_zip( $base_sku, $project_folder );

		if ( !$zip_path ) {
			$this->log_import_error( 'Failed to create on-demand ZIP for: ' . $base_sku );
			return false;
		}

		return $zip_path;
	}

	/**
	 * Find project folder matching base SKU
	 */
	private function find_project_folder( $base_sku ) {
		try {
			$protected_files_dir = WP_CONTENT_DIR . '/csharp-import/Product Files';

			if ( !is_dir( $protected_files_dir ) ) {
				$this->log_import_error( 'Protected files directory does not exist: ' . $protected_files_dir );
				return false;
			}

			$folders = scandir( $protected_files_dir );

			if ( false === $folders ) {
				$this->log_import_error( 'Could not scan protected files directory: ' . $protected_files_dir );
				return false;
			}

			foreach ( $folders as $folder ) {
				if ( '.' === $folder || '..' === $folder ) {
					continue;
				}

				$folder_path = $protected_files_dir . '/' . $folder;

				if ( is_dir( $folder_path ) && strpos( $folder, $base_sku ) === 0 ) {
					return $folder_path;
				}
			}

			return false;
		} catch ( Exception $e ) {
			$this->log_import_error( 'Exception in find_project_folder: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get existing ZIP file or create new one from project folder
	 */
	// TODO Remove this
	private function get_or_create_bundle_zip( $base_sku, $project_folder_path ) {
		try {
			$upload_dir = wp_upload_dir();
			$wc_uploads = $upload_dir['basedir'] . '/woocommerce_uploads';

			// Ensure woocommerce_uploads directory exists
			if ( !file_exists( $wc_uploads ) ) {
				if ( !wp_mkdir_p( $wc_uploads ) ) {
					$this->log_import_error( 'Failed to create woocommerce_uploads directory: ' . $wc_uploads );
					return false;
				}
			}

			// Create bundles subdirectory for organization
			$bundles_dir = $wc_uploads . '/bundles';
			if ( !file_exists( $bundles_dir ) ) {
				if ( !wp_mkdir_p( $bundles_dir ) ) {
					$this->log_import_error( 'Failed to create bundles directory: ' . $bundles_dir );
					return false;
				}
			}

			$zip_filename = $base_sku . '-full-set.zip';
			$zip_path     = $bundles_dir . '/' . $zip_filename;

			// Check if ZIP already exists
			if ( file_exists( $zip_path ) ) {
				return $zip_path;
			}

			// Create new ZIP file
			return $this->create_zip_from_folder( $project_folder_path, $zip_path );
		} catch ( Exception $e ) {
			$this->log_import_error( 'Exception in get_or_create_bundle_zip: ' . $e->getMessage() );
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
				$this->log_import_error( 'ZipArchive class not available. PHP zip extension may not be installed.' );
				return false;
			}

			// Validate source folder
			if ( !is_dir( $source_folder ) ) {
				$this->log_import_error( 'Source folder does not exist: ' . $source_folder );
				return false;
			}

			$zip    = new ZipArchive();
			$result = $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );

			if ( TRUE !== $result ) {
				$this->log_import_error( 'Failed to create ZIP archive. Error code: ' . $result . ', Path: ' . $zip_path );
				return false;
			}

			// Check if we can create RecursiveDirectoryIterator
			try {
				$files = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $source_folder ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( Exception $e ) {
				$this->log_import_error( 'Failed to create recursive directory iterator: ' . $e->getMessage() );
				$zip->close();
				return false;
			}

			$file_count = 0;
			foreach ( $files as $name => $file ) {
				if ( !$file->isDir() ) {
					$file_path     = $file->getRealPath();
					$relative_path = substr( $file_path, strlen( $source_folder ) + 1 );

					if ( $zip->addFile( $file_path, $relative_path ) ) {
						$file_count++;
					} else {
						$this->log_import_error( 'Failed to add file to ZIP: ' . $file_path );
					}
				}
			}

			$zip_result = $zip->close();

			if ( !$zip_result ) {
				$this->log_import_error( 'Failed to close ZIP archive: ' . $zip_path );
				return false;
			}

			if ( 0 === $file_count ) {
				$this->log_import_error( 'No files were added to ZIP archive: ' . $zip_path );
				return false;
			}

			return file_exists( $zip_path ) ? $zip_path : false;
		} catch ( Exception $e ) {
			$this->log_import_error( 'Exception in create_zip_from_folder: ' . $e->getMessage() );
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
			} else {
				error_log( 'File not found in project folder: ' . $filename );
				error_log( 'Project folder: ' . $project_folder );
			}
		}

		// Fallback: search in the old flat structure for backwards compatibility
		// $protected_files_dir = WP_CONTENT_DIR . '/csharp-import/protected-files';
		// return $this->find_file_in_directory( $protected_files_dir, $filename );
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
