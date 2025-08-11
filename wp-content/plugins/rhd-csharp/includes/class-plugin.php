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
		// General plugin hooks can be added here
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

		// Initialize WooCommerce customizations
		new RHD_CSharp_WooCommerce();
	}
}
