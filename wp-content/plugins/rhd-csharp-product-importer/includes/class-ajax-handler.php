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
	 * Finalize import by creating bundles
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

			// Always create bundles
			$results = $bundle_creator->finalize_import( $file_path );

			// Clean up temp file
			unlink( $file_path );

			wp_send_json_success( [
				'message' => __( 'Import finalization completed', 'rhd' ),
				'results' => $results,
			] );

		} catch ( Exception $e ) {
			wp_send_json_error( sprintf( __( 'Finalization failed: %s', 'rhd' ), $e->getMessage() ) );
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
