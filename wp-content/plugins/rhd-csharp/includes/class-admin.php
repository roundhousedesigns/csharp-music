<?php
/**
 * Admin interface class
 */
class RHD_CSharp_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', [$this, 'add_admin_menu'] );
		add_action( 'admin_enqueue_scripts', [$this, 'admin_scripts'] );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'C. Sharp Product Importer', 'rhd' ),
			__( 'C. Sharp Importer', 'rhd' ),
			'manage_woocommerce',
			'rhd-csharp-importer',
			[$this, 'admin_page']
		);
	}

	/**
	 * Enqueue admin scripts
	 */
	public function admin_scripts( $hook ) {
		if ( 'woocommerce_page_rhd-csharp-importer' !== $hook ) {
			return;
		}

		wp_enqueue_script(
			'rhd-csharp-importer-admin',
			RHD_CSHARP_IMPORTER_URL . 'assets/js/admin.js',
			['jquery'],
			RHD_CSHARP_IMPORTER_VERSION,
			true
		);

		wp_localize_script( 'rhd-csharp-importer-admin', 'rhdImporter', [
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'rhd_csv_import' ),
			'strings' => [
				'importing' => __( 'Importing products...', 'rhd' ),
				'success'   => __( 'Import completed successfully!', 'rhd' ),
				'error'     => __( 'Import failed. Please try again.', 'rhd' ),
			],
		] );
	}

	/**
	 * Admin page HTML
	 */
	public function admin_page() {
		include RHD_CSHARP_IMPORTER_PATH . 'templates/admin-form.php';
	}
}
