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
		add_shortcode( 'csharp-grouped-product-bundle-links', [$this, 'csharp_grouped_product_bundle_links'] );
		add_shortcode( 'csharp-grouped-product-bundle-name', [$this, 'csharp_grouped_product_bundle_name'] );
		add_shortcode( 'csharp-single-product-title', [$this, 'csharp_single_product_title'] );
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

	public function csharp_grouped_product_bundle_links() {
		global $product;

		if ( !$product || $product->get_type() !== 'grouped' ) {
			return;
		}

		$output = '';

		$output .= '<div class="grouped-product-bundle-links">';

		// Get product children
		$children = $product->get_children();

		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );
			$output .= '<a href="' . esc_url( $child->get_permalink() ) . '">' . esc_html( $child->get_name() ) . '</a>';
		}

		$output .= '</div>';

		return $output;
	}

	public function csharp_single_product_title( $atts ) {
		global $product;

		if ( !$product ) {
			return;
		}

		$atts = shortcode_atts( [
			'tag' => 'h1',
		], $atts );

		return '<' . $atts['tag'] . ' class="wp-block-heading">' . $product->get_title() . '</' . $atts['tag'] . '>';
	}
}
