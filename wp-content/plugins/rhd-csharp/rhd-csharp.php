<?php
/**
 * Plugin Name: RHD C. Sharp
 * Description: C. Sharp Music custom functionality.
 * Version: 0.1
 * Author: Roundhouse Designs
 * Author URI: https://roundhouse-designs.com
 * Text Domain: rhd
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires at least: 5.0
 * Requires PHP: 8.2
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'RHD_CSHARP_IMPORTER_VERSION', '0.1' );
define( 'RHD_CSHARP_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'RHD_CSHARP_IMPORTER_URL', plugin_dir_url( __FILE__ ) );

// Autoloader for includes
spl_autoload_register( function ( $class ) {
	if ( strpos( $class, 'RHD_CSharp_' ) === 0 ) {
		$class_file = str_replace( 'RHD_CSharp_', '', $class );
		$class_file = str_replace( '_', '-', strtolower( $class_file ) );
		$file_path  = RHD_CSHARP_IMPORTER_PATH . 'includes/class-' . $class_file . '.php';

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
} );

// Initialize the plugin
function rhd_csharp_importer_init() {
	if ( !class_exists( 'RHD_CSharp_Plugin' ) ) {
		require_once RHD_CSHARP_IMPORTER_PATH . 'includes/class-plugin.php';
	}

	new RHD_CSharp_Plugin();
}

add_action( 'plugins_loaded', 'rhd_csharp_importer_init' );
