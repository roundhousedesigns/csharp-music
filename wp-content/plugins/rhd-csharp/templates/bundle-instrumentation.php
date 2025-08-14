<?php
/**
 * Template for the bundle add to cart shortcode.
 *
 * @var $individual_products array
 *
 * @package RHD_CSharp
 */

defined( 'ABSPATH' ) || exit;

global $product;

$individual_products = RHD_CSharp_Woocommerce::get_bundled_products( $product->get_id() );
$instruments = array_map( function( $product ) {
	return $product->get_attribute( 'instrument' );
}, $individual_products );
?>

<div class="rhd-bundle-instrumentation">
	<?php if ( !empty( $instruments ) ): ?>
		<h4 class="rhd-bundle-instrumentation-title"><?php esc_html_e( 'Instrumentation', 'rhd' ); ?></h4>
		<ul class="rhd-bundle-instrumentation-list">
			<?php foreach ( $instruments as $instrument ): ?>
				<li class="rhd-bundle-instrumentation-item">
					<?php echo esc_html( trim( $instrument ) ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>