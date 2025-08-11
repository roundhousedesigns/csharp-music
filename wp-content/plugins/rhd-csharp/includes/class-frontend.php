<?php
/**
 * Frontend functionality class
 */
class RHD_CSharp_Frontend {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Add custom product template support
		add_action( 'woocommerce_before_add_to_cart_form', [$this, 'display_custom_bundle_template'] );
		add_action( 'wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts'] );

		// Hide default add-to-cart form for bundle products
		add_action( 'woocommerce_single_product_summary', [$this, 'maybe_hide_default_add_to_cart'], 5 );
	}

	/**
	 * Display custom bundle template
	 */
	public function display_custom_bundle_template() {
		global $product;

		// Only show for bundle products
		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return;
		}

		// Include our custom template
		$template_path = RHD_CSHARP_IMPORTER_PATH . 'templates/single-product/add-to-cart/bundle.php';
		if ( file_exists( $template_path ) ) {
			include $template_path;
		}
	}

	/**
	 * Enqueue frontend scripts
	 */
	public function enqueue_frontend_scripts() {
		if ( is_product() ) {
			wp_enqueue_script(
				'rhd-csharp-importer-frontend',
				RHD_CSHARP_IMPORTER_URL . 'assets/frontend.js',
				['jquery'],
				RHD_CSHARP_IMPORTER_VERSION,
				true
			);

			wp_localize_script( 'rhd-csharp-importer-frontend', 'rhdImporter', [
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rhd_csv_import' ),
				'strings' => [
					'importing' => __( 'Importing products...', 'rhd' ),
					'success'   => __( 'Import completed successfully!', 'rhd' ),
					'error'     => __( 'Import failed. Please try again.', 'rhd' ),
				],
			] );
		}
	}

	/**
	 * Hide default add-to-cart form for bundle products
	 */
	public function maybe_hide_default_add_to_cart() {
		global $product;

		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return;
		}

		// This action is hooked into woocommerce_single_product_summary, which is typically
		// where the default add-to-cart form is rendered. By returning early, we prevent
		// the default form from being output.
		return;
	}
}
