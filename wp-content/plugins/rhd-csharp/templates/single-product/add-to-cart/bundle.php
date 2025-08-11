<?php
/**
 * Custom bundle template for sheet music products
 * 
 * This template enhances the default WooCommerce Product Bundles template
 * to provide better customer experience for sheet music products.
 */

defined( 'ABSPATH' ) || exit;

global $product;

if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
	return;
}

// Get bundled items using the proper API
$bundled_items = $product->get_bundled_data_items();
$has_bundled_items = !empty( $bundled_items );

if ( !$has_bundled_items ) {
	return;
}

// Get individual products for this bundle
$base_sku = $product->get_meta( '_bundle_base_sku' );
$individual_products = [];

if ( $base_sku ) {
	global $wpdb;
	$like_pattern = $wpdb->esc_like( $base_sku ) . '%';
	$product_ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->postmeta}
		WHERE meta_key = '_sku'
		AND meta_value LIKE %s
		AND meta_value != %s",
		$like_pattern,
		$base_sku
	) );
	
	foreach ( $product_ids as $product_id ) {
		$individual_product = wc_get_product( $product_id );
		if ( $individual_product ) {
			$individual_products[] = $individual_product;
		}
	}
}
?>

<div class="rhd-sheet-music-bundle">

	<div class="audio-samples">
		<h3><?php esc_html_e( 'Audio Samples', 'rhd' ); ?></h3>
		<p class="audio-samples-description">
			<?php esc_html_e( 'Listen to audio samples of the complete set:', 'rhd' ); ?>
		</p>
		<div class="audio-samples-grid">
			<?php
			$pod = pods( 'product', $product->get_id() );
			$audio_files = $pod->field( 'audio_files' );
			foreach ( $audio_files as $attachment ) {
				$attachment_url = wp_get_attachment_url( $attachment['ID'] );
				?>
				<div class="audio-sample">
					<audio src="<?php echo esc_url( $attachment_url ); ?>" controls></audio>
				</div>
			<?php } ?>
		</div>
	</div>
	
	<!-- Full Set Option -->
	<div class="full-set-option">
		<h3><?php esc_html_e( 'Full Set', 'rhd' ); ?></h3>
		<p class="full-set-description">
			<?php esc_html_e( 'Purchase the complete set including all parts:', 'rhd' ); ?>
		</p>
		
		<div class="full-set-parts">
			<?php
			// Display all bundled items
			foreach ( $bundled_items as $bundled_item ) {
				// Handle WC_Bundled_Item_Data objects properly
				$product_id = is_object( $bundled_item ) ? $bundled_item->get_product_id() : $bundled_item['product_id'];
				$bundled_product = wc_get_product( $product_id );
				if ( !$bundled_product ) continue;
				
				$item_name = $bundled_product->get_name();
				?>
				
				<div class="full-set-part">
					<span class="part-name"><?php echo esc_html( $item_name ); ?></span>
				</div>
				
			<?php } ?>
		</div>
		
		<div class="full-set-price">
			<strong><?php esc_html_e( 'Full Set Price:', 'rhd' ); ?> 
			<?php 
			$price_html = $product->get_price_html();
			if ( !empty( $price_html ) ) {
				echo $price_html;
			} else {
				// Fallback: show raw price
				$price = $product->get_price();
				if ( $price > 0 ) {
					echo wc_price( $price );
				} else {
					echo '<span style="color: #999;">Price not set</span>';
				}
			}
			?>
			</strong>
		</div>
		
		<div class="full-set-actions">
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'woocommerce-add-to-cart', 'woocommerce-add-to-cart-nonce' ); ?>
				<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" />
				<button type="submit" class="button add-full-set-to-cart">
					<?php esc_html_e( 'Add Full Set to Cart', 'rhd' ); ?>
				</button>
			</form>
		</div>
	</div>
	
	<!-- Individual Products -->
	<?php if ( !empty( $individual_products ) ) : ?>
		<div class="individual-products">
			<h3><?php esc_html_e( 'Or Purchase Individual Parts', 'rhd' ); ?></h3>
			<p class="individual-description">
				<?php esc_html_e( 'Need just one part? Purchase individual pieces below:', 'rhd' ); ?>
			</p>
			
			<div class="individual-products-grid">
				<?php foreach ( $individual_products as $individual_product ) : ?>
					<div class="individual-product">
						<div class="product-info">
							<h4><?php echo esc_html( $individual_product->get_name() ); ?></h4>
							<div class="product-price"><?php echo $individual_product->get_price_html(); ?></div>
						</div>
						<div class="product-actions">
							<a href="<?php echo esc_url( $individual_product->get_permalink() ); ?>" class="button">
								<?php esc_html_e( 'View Details', 'rhd' ); ?>
							</a>
							<form method="post" enctype="multipart/form-data" style="display: inline;">
								<?php wp_nonce_field( 'woocommerce-add-to-cart', 'woocommerce-add-to-cart-nonce' ); ?>
								<input type="hidden" name="add-to-cart" value="<?php echo esc_attr( $individual_product->get_id() ); ?>" />
								<button type="submit" class="button add-to-cart">
									<?php esc_html_e( 'Add to Cart', 'rhd' ); ?>
								</button>
							</form>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	<?php endif; ?>
	
</div>

<style>
.rhd-sheet-music-bundle {
	margin: 2em 0;
}

.full-set-option,
.individual-products {
	margin-bottom: 2em;
	padding: 1.5em;
	border: 1px solid #ddd;
	border-radius: 4px;
	background: #f9f9f9;
}

.full-set-option h3,
.individual-products h3 {
	margin-top: 0;
	color: #333;
}

.full-set-description,
.individual-description {
	color: #666;
	margin-bottom: 1.5em;
}

.full-set-parts {
	margin-bottom: 1.5em;
}

.full-set-part {
	padding: 0.5em;
	background: white;
	border-radius: 3px;
	margin-bottom: 0.5em;
	border-left: 3px solid #0073aa;
}

.part-name {
	font-weight: 500;
	color: #333;
}

.full-set-price {
	margin: 1.5em 0;
	padding: 1em;
	background: white;
	border-radius: 3px;
	text-align: center;
	font-size: 1.2em;
	border: 2px solid #0073aa;
}

.full-set-actions {
	text-align: center;
}

.full-set-actions .button {
	background: #0073aa;
	color: white;
	padding: 1em 2em;
	font-size: 1.1em;
	font-weight: bold;
	border: none;
	border-radius: 4px;
	cursor: pointer;
	transition: background-color 0.3s;
}

.full-set-actions .button:hover {
	background: #005a87;
}

.individual-products-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
	gap: 1em;
	margin-top: 1em;
}

.individual-product {
	background: white;
	padding: 1em;
	border-radius: 3px;
	border: 1px solid #ddd;
}

.product-info h4 {
	margin: 0 0 0.5em 0;
	font-size: 1em;
}

.product-price {
	color: #666;
	margin-bottom: 1em;
	font-weight: 500;
}

.product-actions {
	display: flex;
	gap: 0.5em;
}

.product-actions .button {
	flex: 1;
	text-align: center;
	font-size: 0.9em;
	padding: 0.5em 1em;
}

@media (max-width: 768px) {
	.individual-products-grid {
		grid-template-columns: 1fr;
	}
}
</style> 