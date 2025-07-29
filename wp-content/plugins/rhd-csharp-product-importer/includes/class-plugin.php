<?php
/**
 * Main plugin orchestrator class
 */
class RHD_CSharp_Plugin {

	/**
	 * Plugin components
	 */
	private $dependency_checker;
	private $admin;
	private $ajax_handler;
	private $frontend;

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'init', [$this, 'init'] );

		// Initialize components
		$this->dependency_checker = new RHD_CSharp_Dependency_Checker();

		// Only initialize other components if dependencies are met
		if ( $this->dependency_checker->dependencies_met() ) {
			$this->admin        = new RHD_CSharp_Admin();
			$this->ajax_handler = new RHD_CSharp_Ajax_Handler();
			$this->frontend     = new RHD_CSharp_Frontend();
		}
	}

	/**
	 * Initialize plugin
	 */
	public function init() {
		// Load text domain
		load_plugin_textdomain( 'rhd', false, dirname( plugin_basename( RHD_CSHARP_IMPORTER_PATH . 'rhd-csharp-product-importer.php' ) ) . '/languages' );
	}
}
