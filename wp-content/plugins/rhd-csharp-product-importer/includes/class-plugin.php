<?php
/**
 * Main plugin class
 */
class RHD_CSharp_Plugin {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_hooks();
		$this->init_components();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		// Add download interception for lazy ZIP creation
		add_filter( 'woocommerce_download_file_redirect', [$this, 'handle_lazy_bundle_download'], 10, 2 );
	}

	/**
	 * Initialize plugin components
	 */
	private function init_components() {
		// Initialize dependency checker
		new RHD_CSharp_Dependency_Checker();

		// Initialize admin interface (only in admin)
		if ( is_admin() ) {
			new RHD_CSharp_Admin();
		}

		// Initialize AJAX handlers
		new RHD_CSharp_Ajax_Handler();

		// Initialize frontend features
		new RHD_CSharp_Frontend();
	}

	/**
	 * Handle lazy bundle download - create ZIP on-demand
	 */
	public function handle_lazy_bundle_download( $file_path, $download ) {
		// Check if this is a lazy bundle download
		if ( strpos( $file_path, 'rhd_lazy:' ) === 0 ) {
			$base_sku = str_replace( 'rhd_lazy:', '', $file_path );
			
			$file_handler = new RHD_CSharp_File_Handler();
			$zip_path = $file_handler->create_bundle_zip_on_demand( $base_sku );
			
			if ( $zip_path ) {
				// Update the download with the real file path
				$download->set_file( $zip_path );
				
				// Update the product with the real download path to avoid recreating ZIP next time
				$this->update_bundle_download_path( $download, $zip_path );
				
				return $zip_path;
			} else {
				// ZIP creation failed, return error
				wp_die( __( 'Download file could not be created. Please contact support.', 'rhd' ) );
			}
		}
		
		return $file_path;
	}

	/**
	 * Update bundle download with real ZIP path to avoid future on-demand creation
	 */
	private function update_bundle_download_path( $download, $zip_path ) {
		// This is a bit complex as we need to find the product and update its downloads
		// For now, we'll let it recreate each time since ZIP creation is fast once the file exists
		// TODO: Optimize by updating the product's download metadata
	}
}
