<?php
/**
 * Plugin dependency checker
 */
class RHD_CSharp_Dependency_Checker {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_notices', [$this, 'check_dependencies'] );
	}

	/**
	 * Check if all dependencies are met
	 */
	public function dependencies_met() {
		return class_exists( 'WooCommerce' ) && class_exists( 'WC_Bundles' );
	}

	/**
	 * Check plugin dependencies and show notices
	 */
	public function check_dependencies() {
		$missing_plugins = [];

		if ( !class_exists( 'WooCommerce' ) ) {
			$missing_plugins[] = 'WooCommerce';
		}

		if ( !class_exists( 'WC_Bundles' ) ) {
			$missing_plugins[] = 'WooCommerce Product Bundles';
		}

		if ( !empty( $missing_plugins ) ) {
			echo '<div class="notice notice-error"><p>';
			echo sprintf(
				__( 'RHD C. Sharp Product Importer requires the following plugins to be installed and activated: %s', 'rhd' ),
				implode( ', ', $missing_plugins )
			);
			echo '</p></div>';
		}
	}
}
