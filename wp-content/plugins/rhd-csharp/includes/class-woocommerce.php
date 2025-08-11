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
		// Download hooks (removed lazy ZIP creation; bundles now use direct downloads like simple products)

		// Product hooks
		add_action( 'woocommerce_single_product_summary', [ $this, 'add_custom_product_content' ], 25 );
		add_filter( 'woocommerce_product_tabs', [ $this, 'customize_product_tabs' ] );

		// Cart hooks
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', [ $this, 'customize_cart_item_prices' ] );

		// Checkout hooks
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_fields' ] );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'process_order_completion' ], 10, 3 );

		// Order hooks
		add_action( 'woocommerce_order_status_completed', [ $this, 'handle_order_completion' ] );
		add_filter( 'woocommerce_order_item_name', [ $this, 'customize_order_item_name' ], 10, 2 );

		// Email hooks
		add_action( 'woocommerce_email_before_order_table', [ $this, 'add_email_content' ], 10, 4 );

		// Admin hooks
		add_filter( 'woocommerce_product_data_tabs', [ $this, 'add_custom_product_data_tab' ] );
		add_action( 'woocommerce_product_data_panels', [ $this, 'add_custom_product_data_fields' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save_custom_product_fields' ] );

		// My Account hooks
		add_filter( 'woocommerce_account_menu_items', [ $this, 'customize_my_account_menu' ] );
		add_action( 'init', [ $this, 'add_custom_endpoint' ] );

		// Shop customizations
		add_action( 'woocommerce_before_shop_loop', [ $this, 'add_shop_content' ] );
		add_filter( 'woocommerce_loop_product_link', [ $this, 'customize_product_links' ], 10, 2 );

		// Product block filters - exclude Simple Products from Product blocks
		// This hides Simple Products from Query Loop blocks and Product blocks in both frontend and editor
		add_filter( 'query_loop_block_query_vars', [ $this, 'filter_product_blocks_frontend' ], 10, 2 );
		add_filter( 'rest_product_query', [ $this, 'filter_product_blocks_editor' ], 10, 2 );
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
		// Example: Add a custom tab for music products
		$tabs['music_info'] = [
			'title'    => __( 'Music Info', 'rhd' ),
			'priority' => 25,
			'callback' => [ $this, 'music_info_tab_content' ],
		];

		return $tabs;
	}

	/**
	 * Content for custom music info tab
	 */
	public function music_info_tab_content() {
		global $product;

		echo '<div class="woocommerce-tabs-panel woocommerce-tabs-panel--music_info panel entry-content wc-tab" id="tab-music_info">';
		echo '<h2>' . esc_html__( 'Music Information', 'rhd' ) . '</h2>';

		// Add custom music-related information here
		echo '<p>' . esc_html__( 'Additional music information will be displayed here.', 'rhd' ) . '</p>';

		echo '</div>';
	}

	/**
	 * Validate items being added to cart
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		// Example: Custom validation logic
		$product = wc_get_product( $product_id );

		if ( $product && $product->is_downloadable() ) {
			// Add any custom validation for downloadable music products
		}

		return $passed;
	}

	/**
	 * Customize cart item prices
	 */
	public function customize_cart_item_prices( $cart ) {
		if ( is_admin() && !defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Example: Apply custom pricing logic
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			// Custom pricing logic can be added here
		}
	}

	/**
	 * Validate checkout fields
	 */
	public function validate_checkout_fields() {
		// Example: Custom checkout validation
		// Add validation logic for music-specific requirements
	}

	/**
	 * Process order completion
	 */
	public function process_order_completion( $order_id, $posted_data, $order ) {
		// Example: Custom logic when order is processed
		error_log( 'RHD C.Sharp: Order processed - ID: ' . $order_id );
	}

	/**
	 * Handle order completion
	 */
	public function handle_order_completion( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( !$order ) {
			return;
		}

		// Example: Send custom completion emails, trigger integrations, etc.
		error_log( 'RHD C.Sharp: Order completed - ID: ' . $order_id );
	}

	/**
	 * Customize order item names
	 */
	public function customize_order_item_name( $item_name, $item ) {
		// Example: Add custom formatting to order item names
		return $item_name;
	}

	/**
	 * Add content to emails
	 */
	public function add_email_content( $order, $sent_to_admin, $plain_text, $email ) {
		// Example: Add custom content to WooCommerce emails
		if ( 'customer_completed_order' === $email->id ) {
			echo '<div class="rhd-email-content">';
			echo '<p>' . esc_html__( 'Thank you for your music purchase!', 'rhd' ) . '</p>';
			echo '</div>';
		}
	}

	/**
	 * Add custom product data tab in admin
	 */
	public function add_custom_product_data_tab( $tabs ) {
		$tabs['rhd_music_data'] = [
			'label'    => __( 'Music Data', 'rhd' ),
			'target'   => 'rhd_music_data_options',
			'priority' => 21,
		];

		return $tabs;
	}

	/**
	 * Add custom product data fields
	 */
	public function add_custom_product_data_fields() {
		echo '<div id="rhd_music_data_options" class="panel woocommerce_options_panel">';

		woocommerce_wp_text_input( [
			'id'          => '_rhd_artist',
			'label'       => __( 'Artist', 'rhd' ),
			'placeholder' => __( 'Enter artist name', 'rhd' ),
			'desc_tip'    => true,
			'description' => __( 'The artist or band name for this music.', 'rhd' ),
		] );

		woocommerce_wp_text_input( [
			'id'          => '_rhd_album',
			'label'       => __( 'Album', 'rhd' ),
			'placeholder' => __( 'Enter album name', 'rhd' ),
			'desc_tip'    => true,
			'description' => __( 'The album this track belongs to.', 'rhd' ),
		] );

		woocommerce_wp_text_input( [
			'id'          => '_rhd_duration',
			'label'       => __( 'Duration', 'rhd' ),
			'placeholder' => __( 'e.g., 3:45', 'rhd' ),
			'desc_tip'    => true,
			'description' => __( 'The duration of this track.', 'rhd' ),
		] );

		echo '</div>';
	}

	/**
	 * Save custom product fields
	 */
	public function save_custom_product_fields( $post_id ) {
		$fields = [ '_rhd_artist', '_rhd_album', '_rhd_duration' ];

		foreach ( $fields as $field ) {
			if ( isset( $_POST[$field] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( $_POST[$field] ) );
			}
		}
	}

	/**
	 * Customize My Account menu
	 */
	public function customize_my_account_menu( $items ) {
		// Example: Add custom menu item
		$items['music-downloads'] = __( 'My Music', 'rhd' );

		return $items;
	}

	/**
	 * Add custom endpoint for My Account
	 */
	public function add_custom_endpoint() {
		add_rewrite_endpoint( 'music-downloads', EP_ROOT | EP_PAGES );
	}

	/**
	 * Add content before shop loop
	 */
	public function add_shop_content() {
		echo '<div class="rhd-shop-notice">';
		echo '<p>' . esc_html__( 'Browse our collection of digital music.', 'rhd' ) . '</p>';
		echo '</div>';
	}

	/**
	 * Customize product links
	 */
	public function customize_product_links( $link, $product ) {
		// Example: Modify product links if needed
		return $link;
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
				'taxonomy' => 'product_type',
				'field'    => 'slug',
				'terms'    => 'simple',
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
}