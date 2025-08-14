<?php
/**
 * Shortcodes
 *
 * Handles all shortcodes
 */
class RHD_CSharp_Shortcodes {

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init_shortcodes();
	}

	/**
	 * Initialize shortcodes
	 */
	private function init_shortcodes() {
		add_shortcode( 'csharp-product-audio', [$this, 'csharp_product_audio_shortcode'] );
		add_shortcode( 'csharp-bundle-instrumentation', [$this, 'csharp_bundle_instrumentation'] );
		add_shortcode( 'csharp-purchase-bundled-items', [$this, 'csharp_purchase_bundled_items'] );
	}

	/**
	 * Shortcode for audio player
	 */
	public function csharp_product_audio_shortcode() {
		$product_id  = get_the_ID();
		$pod         = pods( 'product', $product_id );
		$audio_files = $pod->field( 'audio_files' );
		$output      = '';

		if ( empty( $audio_files ) ) {
			error_log( 'no audio files for ' . $product_id );
			return '';
		}

		$output .= '<div class="audio-samples">';

		foreach ( $audio_files as $attachment ) {
			$attachment_url = wp_get_attachment_url( $attachment['ID'] );
			$output .= '<div class="audio-sample">
				<audio src="' . esc_url( $attachment_url ) . '" controls></audio>
			</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Shortcode for bundle instrumentation
	 */
	public function csharp_bundle_instrumentation() {
		global $product;

		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return;
		}

		ob_start();
		include RHD_CSHARP_PLUGIN_DIR . 'templates/bundle-instrumentation.php';
		return apply_filters( 'the_content', ob_get_clean() );
	}

	/**
	 * Shortcode for custom bundle add to cart
	 */
	public function csharp_purchase_bundled_items() {
		global $product;

		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return;
		}

		ob_start();
		include RHD_CSHARP_PLUGIN_DIR . 'templates/purchase-bundled-items.php';
		return apply_filters( 'the_content', ob_get_clean() );
	}
}
