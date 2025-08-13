<?php
/**
 * Template for the bundle add to cart shortcode.
 *
 * @var $individual_products array
 *
 * @package RHD_CSharp
 */

defined( 'ABSPATH' ) || exit;
?>

<div class="rhd-sheet-music-bundle">
	<!-- Individual Products -->
	<?php if ( !empty( $individual_products ) ): ?>
		<?php $accordion_id = 'rhd-acc-' . uniqid(); ?>
		<div class="individual-products rhd-accordion" data-accordion="rhd-sheet-music-bundle">
			<h3 class="rhd-accordion-header"><button class="rhd-accordion-trigger" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $accordion_id ); ?>"><?php esc_html_e( 'Or Purchase Individual Parts', 'rhd' ); ?><span class="rhd-accordion-trigger-icon">&nbsp;&nbsp;&rsaquo;</span></button></h3>
			<div id="<?php echo esc_attr( $accordion_id ); ?>" class="rhd-accordion-panel" hidden>
				<p class="individual-description">
					<?php esc_html_e( 'Purchase single instrument charts below:', 'rhd' ); ?>
				</p>

				<div class="individual-products-grid">

					<?php foreach ( $individual_products as $individual_product ): ?>
						<div class="individual-product">
							<div class="product-info">
								<h4><?php echo esc_html( $individual_product->get_name() ); ?></h4>
								<div class="product-actions">
									<div class="product-price"><?php echo $individual_product->get_price_html(); ?></div>
									<div class="product-add-to-cart">
										<a href="<?php echo $individual_product->add_to_cart_url() ?>" value="<?php echo esc_attr( $individual_product->get_id() ); ?>" class="ajax_add_to_cart add_to_cart_button wp-block-button__link" data-product_id="<?php echo get_the_ID(); ?>" aria-label="Add “<?php the_title_attribute() ?>” to your cart"> 
											<?php esc_html_e( 'Add to Cart', 'rhd' ); ?>
										</a>
									</div>
								</div>
							</div>
						</div>
					<?php endforeach; ?>

				</div>
			</div>
		</div>
	<?php endif; ?>

</div>