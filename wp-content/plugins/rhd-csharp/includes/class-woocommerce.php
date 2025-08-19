<?php
/**
 * WooCommerce customizations class
 *
 * Handles all WooCommerce-related hooks, filters, and customizations
 */
class RHD_CSharp_WooCommerce {

	/**
	 * Constructor
	 */
	public function __construct() {
		// Only initialize if WooCommerce is active
		if ( !$this->is_woocommerce_active() ) {
			return;
		}

		$this->init_hooks();
	}

	/**
	 * Check if WooCommerce is active
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Initialize WooCommerce hooks and filters
	 */
	private function init_hooks() {
		// Product hooks
		add_action( 'woocommerce_single_product_summary', [$this, 'add_custom_product_content'], 25 );
		add_filter( 'woocommerce_product_tabs', [$this, 'customize_product_tabs'] );
		add_action( 'wp', [$this, 'remove_image_zoom_support'], 100 );
		add_filter( 'woocommerce_product_data_store_cpt_get_products_query', [$this, 'exclude_product_tag_query'], 10, 2 );

		// Product block and archive filters - exclude Simple Products from Product blocks and archive templates
		// This hides Simple Products from Query Loop blocks and Product blocks in both frontend and editor
		add_filter( 'query_loop_block_query_vars', [$this, 'filter_product_blocks_frontend'], 10, 2 );
		add_filter( 'rest_product_query', [$this, 'filter_product_blocks_editor'], 10, 2 );
		add_action( 'woocommerce_product_query_tax_query', [$this, 'filter_product_archive'] );
		add_filter( 'woocommerce_related_products', [$this, 'filter_related_products'], 10, 3 );

		// Remove Add to Cart block bundle listing
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_details_wrapper_open', 0 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_thumbnail', 5 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_details_open', 10 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_title', 15 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_description', 20 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_product_details', 25 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_details_close', 30 );
		remove_action( 'woocommerce_bundled_item_details', 'wc_pb_template_bundled_item_details_wrapper_close', 100 );
	}

	/**
	 * Add custom content to single product pages
	 */
	public function add_custom_product_content() {
		global $product;

		// Example: Add custom notice for digital music products
		if ( $product && $product->is_downloadable() ) {
			echo '<div class="rhd-music-notice">';
			echo '<p>' . esc_html__( 'This is a digital music download.', 'rhd' ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Customize product tabs
	 */
	public function customize_product_tabs( $tabs ) {
		// Remove the Additional Information tab
		unset( $tabs['additional_information'] );

		return $tabs;
	}

	/**
	 * Remove image zoom support
	 */
	public function remove_image_zoom_support() {
		remove_theme_support( 'wc-product-gallery-zoom' );
	}

	/**
	 * Filter product archive to only show bundled and grouped products
	 *
	 * @param  array $tax_query The tax query array
	 * @return array The filtered tax query array
	 */
	public function filter_product_archive( $tax_query ) {
		$term_slug   = 'individual-single-instrument';
		$tax_query[] = [
			'taxonomy' => 'product_tag',
			'field'    => 'slug',
			'terms'    => $term_slug,
			'operator' => 'NOT IN',
		];

		return $tax_query;
	}

	/**
	 * Filter related products to only show bundled and grouped products
	 *
	 * @param  int[] $related_posts The related products array
	 * @param  int   $product_id    The product ID
	 * @param  array $args
	 * @return array The filtered related products array
	 */
	public function filter_related_products( $related_posts, $product_id, $args ) {
		// TODO Figure out how to intercept the original wc_get_related_products call instead of essentially tossing the results and re-running it afresh here...if that's even possible or worth it.

		$term_slug = 'individual-single-instrument';
		$limit     = 5;

		$product_cats = wc_get_product_term_ids( $product_id, 'product_cat' );

		$related_products = new WP_Query( [
			'post_type'      => 'product',
			'post__not_in'   => [$product_id],
			'posts_per_page' => $limit,
			'tax_query'      => [
				'relation' => 'AND',
				[
					'taxonomy' => 'product_tag',
					'field'    => 'slug',
					'terms'    => $term_slug,
					'operator' => 'NOT IN',
				],
				[
					'taxonomy' => 'product_cat',
					'field'    => 'id',
					'terms'    => $product_cats,
					'operator' => 'IN',
				],
			],
		] );

		return wp_list_pluck( $related_products->posts, 'ID' );
	}

	/**
	 * Exclude individual single instrument from related products query
	 *
	 * @param  array $query      The query array
	 * @param  array $query_vars The query vars array
	 * @return array The filtered query array
	 */
	public function exclude_product_tag_query( $query, $query_vars ) {
		if ( !empty( $query_vars['exclude_tag'] ) ) {
			$query['tax_query'][] = [
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => $query_vars['exclude_tag'],
				'operator' => 'NOT IN',
			];
		}
		return $query;
	}

	/**
	 * Filter Product blocks on frontend - exclude Simple Products
	 */
	public function filter_product_blocks_frontend( $query_vars, $block ) {
		// Only apply to product queries
		if ( isset( $query_vars['post_type'] ) && 'product' === $query_vars['post_type'] ) {
			// Add tax_query to exclude Simple Products
			if ( !isset( $query_vars['tax_query'] ) ) {
				$query_vars['tax_query'] = [];
			}

			$query_vars['tax_query'][] = [
				'taxonomy' => 'product_tag',
				'field'    => 'slug',
				'terms'    => 'individual-single-instrument',
				'operator' => 'NOT IN',
			];
		}

		return $query_vars;
	}

	/**
	 * Filter Product blocks in editor - exclude Simple Products
	 */
	public function filter_product_blocks_editor( $args, $request ) {
		// Add tax_query to exclude Simple Products
		if ( !isset( $args['tax_query'] ) ) {
			$args['tax_query'] = [];
		}

		$args['tax_query'][] = [
			'taxonomy' => 'product_type',
			'field'    => 'slug',
			'terms'    => 'simple',
			'operator' => 'NOT IN',
		];

		return $args;
	}

	/**
	 * Helper method to get custom product meta
	 */
	public function get_product_meta( $product_id, $meta_key ) {
		return get_post_meta( $product_id, $meta_key, true );
	}

	/**
	 * Helper method to check if product is a music product
	 */
	public function is_music_product( $product_id ) {
		$product = wc_get_product( $product_id );
		return $product && $product->is_downloadable();
	}

	/**
	 * Get individual products for a bundle product
	 *
	 * @param  int          $product_id The product ID
	 * @return WC_Product[] The individual products array
	 */
	public static function get_bundled_products( $product_id ) {
		$product = wc_get_product( $product_id );

		// Check if this is actually a bundle product
		if ( !$product || !is_a( $product, 'WC_Product_Bundle' ) ) {
			return [];
		}

		// Get bundled items using the proper API
		$bundled_items     = $product->get_bundled_data_items();
		$has_bundled_items = !empty( $bundled_items );

		if ( !$has_bundled_items ) {
			return [];
		}

		// Get individual products for this bundle
		$base_sku            = $product->get_meta( '_bundle_base_sku' );
		$individual_products = [];

		if ( $base_sku ) {
			global $wpdb;
			$like_pattern = $wpdb->esc_like( $base_sku ) . '%';
			$product_ids  = $wpdb->get_col( $wpdb->prepare(
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

		// Sort products by ensemble type and instrument order
		return self::sort_products_by_ensemble_order( $individual_products );
	}

	/**
	 * Sort products by ensemble type and instrument order
	 *
	 * @param  WC_Product[] $products Array of WC_Product objects
	 * @return WC_Product[] Sorted array of products
	 */
	public static function sort_products_by_ensemble_order( $products ) {
		if ( empty( $products ) ) {
			return $products;
		}

		// Define instrument order for each ensemble type
		$ensemble_orders = [
			'CONCERT BAND'           => [
				'Piccolo', 'Flutes', 'Oboe', 'Bassoon', 'Eb Clarinet', 'Clarinets', 'Alto Clarinet',
				'Bass Clarinet', 'Contralto Clarinet', 'Contrabass Clarinet', 'Alto Saxes', 'Tenor Sax',
				'Baritone Sax', 'Trumpets', 'French Horns', 'Trombones', 'Baritone/Euphonium',
				'Baritone/Euphonium TC', 'Tuba', 'Bells', 'Xylophone', 'Vibraphone', 'Marimba',
				'Percussion', 'Cymbals', 'Snare/Bass Drum', 'Timpani', 'Piano',
			],
			'JAZZ ENSEMBLE'          => [
				'Soprano Sax', 'Alto Sax 1', 'Alto Sax 2', 'Tenor Sax 1', 'Tenor Sax 2', 'Baritone Sax',
				'Trumpet 1', 'Trumpet 2', 'Trumpet 3', 'Trumpet 4', 'Trombone 1', 'Trombone 2',
				'Trombone 3', 'Trombone 4 (Bass)', 'Guitar', 'Bass', 'Percussion (Mallets)',
				'Drums (Drums Set)', 'Piano (Keyboard)',
			],
			'JAZZ COMBO'             => [
				'Alto Sax', 'Tenor Sax', 'Bari Sax', 'Trumpet 1', 'Trumpet 2', 'Trombone', 'Guitar',
				'Bass Drums', 'Piano', 'Alternate parts',
			],
			'PERCUSSION ENSEMBLE'    => [
				'Bells', 'Xylophones', 'Vibraphones', 'Marimbas', 'Timpani', 'Percussion', 'Cymbals',
				'Electric Bass', 'Snare Drum', 'Bass Drum', 'Piano',
			],
			'BRASS QUINTET (SEXTET)' => [
				'Trumpet 1', 'Trumpet 2', 'French Horn', 'Trombone', 'Baritone (Euphonium)', 'Tuba',
				'Percussion (Drums)',
			],
			'NEW ORLEANS FAVORITES'  => [
				'Clarinet', 'Trumpet (Cornet)', 'Trombone', 'Tuba', 'Piano/Banjo', 'Drums', 'Tenor Sax',
				'Alto Sax',
			],
			'CAROLS FOR LOW BRASS'   => [
				'Part 1', 'Part 2', 'Part 3', 'Part 4', 'Drums', 'Part 1 (TC)', 'Part 2 (TC)',
				'Part 3 (TC)', 'Part 4 (8va)',
			],
		];

		// Determine the ensemble type from the first product
		$ensemble_type = '';
		if ( !empty( $products ) ) {
			$first_product = $products[0];
			$ensemble_type = $first_product->get_attribute( 'ensemble-type' );
		}

		// Get the order array for this ensemble type
		$instrument_order = $ensemble_orders[$ensemble_type] ?? [];

		// If no specific order found, return products as-is
		if ( empty( $instrument_order ) ) {
			return $products;
		}

		// Create a mapping of instrument names to their order index
		$order_map = array_flip( $instrument_order );

		// Sort products based on instrument order
		usort( $products, function ( $a, $b ) use ( $order_map ) {
			$instrument_a = trim( $a->get_attribute( 'instrument' ) );
			$instrument_b = trim( $b->get_attribute( 'instrument' ) );

			$order_a = isset( $order_map[$instrument_a] ) ? $order_map[$instrument_a] : 999;
			$order_b = isset( $order_map[$instrument_b] ) ? $order_map[$instrument_b] : 999;

			return $order_a - $order_b;
		} );

		return $products;
	}

}
