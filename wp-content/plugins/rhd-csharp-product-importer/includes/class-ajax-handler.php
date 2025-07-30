<?php
/**
 * AJAX request handler class
 */
class RHD_CSharp_Ajax_Handler {

	/**
	 * Constructor
	 */
	public function __construct() {
		// AJAX handlers
		add_action( 'wp_ajax_rhd_get_csv_info', [$this, 'ajax_get_csv_info'] );
		add_action( 'wp_ajax_rhd_process_chunk', [$this, 'ajax_process_chunk'] );
		add_action( 'wp_ajax_rhd_finalize_import', [$this, 'ajax_finalize_import'] );
		add_action( 'wp_ajax_rhd_process_bundles', [$this, 'ajax_process_bundles'] );
	}

	/**
	 * Get CSV file information
	 */
	public function ajax_get_csv_info() {
		if ( !$this->verify_ajax_request() ) {
			return;
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
	 * Process CSV in chunks
	 */
	public function ajax_process_chunk() {
		if ( !$this->verify_ajax_request() ) {
			return;
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
			$csv_parser       = new RHD_CSharp_CSV_Parser();
			$product_importer = new RHD_CSharp_Product_Importer();

			$csv_data   = $csv_parser->parse( $file_path );
			$total_rows = count( $csv_data );

			// Get the chunk of data to process
			$chunk_data = array_slice( $csv_data, $offset, $chunk_size );

			$results = $product_importer->process_chunk( $chunk_data, $update_existing );

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
	 * Finalize import by preparing bundle creation data
	 */
	public function ajax_finalize_import() {
		if ( !$this->verify_ajax_request() ) {
			return;
		}

		$temp_file = sanitize_text_field( $_POST['temp_file'] ?? '' );

		if ( empty( $temp_file ) ) {
			wp_send_json_error( __( 'No temporary file specified', 'rhd' ) );
		}

		$file_path = wp_upload_dir()['basedir'] . '/' . $temp_file;
		if ( !file_exists( $file_path ) ) {
			wp_send_json_error( __( 'Temporary file not found', 'rhd' ) );
		}

		try {
			$csv_parser     = new RHD_CSharp_CSV_Parser();
			$bundle_creator = new RHD_CSharp_Bundle_Creator();

			// Prepare bundle creation data instead of creating all bundles
			$bundle_families = $bundle_creator->prepare_bundle_families( $file_path );

			// Store bundle families data for chunked processing
			$bundle_data_file = wp_upload_dir()['basedir'] . '/rhd_bundle_data_' . time() . '.json';
			file_put_contents( $bundle_data_file, json_encode( $bundle_families ) );

			wp_send_json_success( [
				'message' => __( 'Products imported successfully', 'rhd' ),
				'bundle_data_file' => basename( $bundle_data_file ),
				'total_bundles' => count( $bundle_families ),
				'results' => [
					'bundles_prepared' => count( $bundle_families ),
					'errors' => []
				]
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( sprintf( __( 'Finalization failed: %s', 'rhd' ), $e->getMessage() ) );
		} finally {
			// Clean up temp file
			if ( file_exists( $file_path ) ) {
				unlink( $file_path );
			}
		}
	}

	/**
	 * Process bundles in chunks
	 */
	public function ajax_process_bundles() {
		if ( !$this->verify_ajax_request() ) {
			return;
		}

		$bundle_data_file = sanitize_text_field( $_POST['bundle_data_file'] ?? '' );
		$offset = intval( $_POST['offset'] ?? 0 );
		$chunk_size = intval( $_POST['chunk_size'] ?? 5 ); // Process 5 bundles at a time

		if ( empty( $bundle_data_file ) ) {
			wp_send_json_error( __( 'No bundle data file specified', 'rhd' ) );
		}

		$file_path = wp_upload_dir()['basedir'] . '/' . $bundle_data_file;
		if ( !file_exists( $file_path ) ) {
			wp_send_json_error( __( 'Bundle data file not found', 'rhd' ) );
		}

		try {
			$bundle_families = json_decode( file_get_contents( $file_path ), true );
			$total_bundles = count( $bundle_families );

			// Get chunk of bundle families to process
			$families_chunk = array_slice( $bundle_families, $offset, $chunk_size, true );

			$bundle_creator = new RHD_CSharp_Bundle_Creator();
			$file_handler = new RHD_CSharp_File_Handler(); // Create shared file handler to collect errors
			
			$results = [
				'bundles_created' => 0,
				'errors' => [],
				'file_not_found' => []
			];

			// Process each family in the chunk
			foreach ( $families_chunk as $base_sku => $family_data ) {
				try {
					error_log( 'RHD Import: Creating bundle for base SKU: ' . $base_sku );
					$bundle_id = $bundle_creator->create_product_bundle( $base_sku, $family_data, false, $file_handler ); // Pass file handler
					if ( $bundle_id ) {
						$results['bundles_created']++;
						error_log( 'RHD Import: Successfully created bundle ' . $bundle_id . ' for base SKU: ' . $base_sku );
					} else {
						$error_msg = sprintf( __( 'Failed to create bundle for %s', 'rhd' ), $base_sku );
						$results['errors'][] = $error_msg;
						error_log( 'RHD Import: ' . $error_msg );
					}
				} catch ( Exception $e ) {
					$error_msg = sprintf( __( 'Error creating bundle for %s: %s', 'rhd' ), $base_sku, $e->getMessage() );
					$results['errors'][] = $error_msg;
					error_log( 'RHD Import: Exception creating bundle for ' . $base_sku . ': ' . $e->getMessage() );
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

			$processed = $offset + count( $families_chunk );
			$is_complete = $processed >= $total_bundles;

			// Clean up bundle data file if we're done
			if ( $is_complete ) {
				unlink( $file_path );
			}

			wp_send_json_success( [
				'processed' => $processed,
				'total' => $total_bundles,
				'is_complete' => $is_complete,
				'results' => $results,
				'message' => sprintf( __( 'Processed %d of %d bundles', 'rhd' ), $processed, $total_bundles )
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( sprintf( __( 'Bundle processing failed: %s', 'rhd' ), $e->getMessage() ) );
		}
	}

	/**
	 * Verify AJAX request
	 */
	private function verify_ajax_request() {
		// Verify nonce
		if ( !wp_verify_nonce( $_POST['nonce'] ?? '', 'rhd_csv_import' ) ) {
			wp_die( __( 'Security check failed', 'rhd' ) );
		}

		// Check user permissions
		if ( !current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Insufficient permissions', 'rhd' ) );
		}

		return true;
	}
}
