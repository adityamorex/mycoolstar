<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * When staff create a brand-new WooCommerce product (rather than one already
 * synced in from SalesOn), automatically creates the matching product over
 * in SalesOn too, on first save - so a new product doesn't sit priced only
 * on the website with nothing on the SalesOn side.
 *
 * Hooked to `woocommerce_process_product_meta` rather than `save_post_product`:
 * that action fires from inside WooCommerce's own product-data meta box save
 * handler, AFTER WooCommerce has already written price/stock meta for this
 * request. `save_post_product` fires earlier in the same request, before
 * WooCommerce has necessarily persisted its own meta (ordering between two
 * `save_post` callbacks at the same priority is registration-order dependent,
 * not guaranteed) - reading `get_regular_price()`/stock at that point risks
 * seeing stale/empty values on a brand-new product's first save. Using
 * WooCommerce's own dedicated action instead makes sure the multipart
 * "Add Product" request we send to SalesOn carries the price/stock the
 * merchant actually just typed in.
 *
 * The missing-featured-image reminder is folded into the SAME callback
 * (rather than a separate save_post_product hook) precisely so the two
 * concerns don't need to coordinate hook priority against each other - one
 * hook, one save event, two independent, non-blocking side effects.
 */
class Saleson_Product_Creator {

	const META_KEY = '_saleson_product_id';

	public static function init() {
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'handle_product_save' ), 20, 1 );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );
	}

	public static function handle_product_save( $post_id ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		// Soft reminder only - runs on every save, never blocks anything.
		self::maybe_flag_missing_image( $post_id );

		// Idempotency guard: only ever create this product in SalesOn once.
		$existing_id = get_post_meta( $post_id, self::META_KEY, true );
		if ( ! empty( $existing_id ) ) {
			return;
		}

		self::create_in_saleson( $post_id, $product );
	}

	// --- Soft "no featured image" reminder ------------------------------------

	private static function maybe_flag_missing_image( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}

		if ( has_post_thumbnail( $post_id ) ) {
			delete_transient( 'saleson_missing_image_' . $post_id );
			return;
		}

		set_transient( 'saleson_missing_image_' . $post_id, get_the_title( $post_id ), 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Renders both the missing-image reminder and any SalesOn create-failure
	 * notice for the product currently open in the editor. Neither of these
	 * ever prevented the save itself - they're purely informational.
	 */
	public static function render_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( ! $post_id ) {
			return;
		}

		$image_title = get_transient( 'saleson_missing_image_' . $post_id );
		if ( $image_title ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: product title */
						__( '"%s" was published without a featured image. SalesOn has no image field, so WooCommerce is the only place this product\'s photos live - consider adding one when you get a chance.', 'saleson-woo-sync' ),
						$image_title
					)
				)
			);
		}

		$create_error = get_transient( 'saleson_create_error_' . $post_id );
		if ( $create_error ) {
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $create_error ) );
			delete_transient( 'saleson_create_error_' . $post_id );
		}
	}

	// --- SalesOn product creation ---------------------------------------------

	private static function create_in_saleson( $post_id, $product ) {
		$price = $product->get_regular_price();
		if ( '' === $price || null === $price ) {
			$price = $product->get_price();
		}
		$price = ( '' !== $price && null !== $price ) ? (float) $price : 0;

		if ( $price <= 0 ) {
			// Nothing usable to send yet (e.g. draft with no price set) - the
			// idempotency guard means this will simply be retried on the next
			// save once a price is entered, since _saleson_product_id is
			// never set here.
			error_log( sprintf( '[Saleson Product Creator] Skipping SalesOn create for Woo product #%d - no price set yet.', $post_id ) );
			return;
		}

		$stock = $product->get_stock_quantity();
		$stock = ( null !== $stock ) ? (int) $stock : 0;

		$warehouse_entry = wp_json_encode( array(
			'warehouse_id' => Saleson_API::WAREHOUSE_ID,
			'stock'        => $stock,
			'rate'         => $price,
		) );

		$fields = array(
			'state'       => 'ACTIVE',
			'unit'        => 'Pcs',
			'name'        => $product->get_name(),
			'pp_with_gst' => $price,
			'sp_with_gst' => $price,
			'sell_price'  => $price,
			'warehouses'  => array( $warehouse_entry ),
		);

		$api    = new Saleson_API();
		$result = $api->request_multipart( 'POST', 'products', array(), $fields );

		if ( empty( $result['ok'] ) || empty( $result['data']['product']['id'] ) ) {
			$error = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'saleson-woo-sync' );
			error_log( sprintf(
				'[Saleson Product Creator] Failed to create SalesOn product for Woo product #%d: %s',
				$post_id,
				is_array( $error ) ? wp_json_encode( $error ) : (string) $error
			) );
			set_transient(
				'saleson_create_error_' . $post_id,
				__( 'Could not create this product in SalesOn automatically - it will need to be added manually, or will be retried on the next save.', 'saleson-woo-sync' ),
				5 * MINUTE_IN_SECONDS
			);
			return;
		}

		$saleson_product_id = (int) $result['data']['product']['id'];

		update_post_meta( $post_id, self::META_KEY, $saleson_product_id );

		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'saleson_product_map',
			array(
				'saleson_product_id' => $saleson_product_id,
				'woo_product_id'     => $post_id,
				'mapping_status'     => 'matched',
				'confidence'         => 'exact',
				'source'             => 'website_created',
				'mapped_at'          => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);

		// Creating the product in SalesOn only sets one base warehouse-level
		// price - it does NOT add the product to any of the 4 group price
		// lists (Phase 0 confirmed). Add it to RETAIL CUSTOMER immediately so
		// it's priced somewhere, not left un-priced everywhere until a staff
		// member manually visits SalesOn Pricing.
		if ( class_exists( 'Saleson_Price_Writeback' ) ) {
			$tier_result = Saleson_Price_Writeback::add_product_to_tier(
				$saleson_product_id,
				Saleson_API::PRICE_LIST_RETAIL,
				$price
			);

			if ( empty( $tier_result['ok'] ) ) {
				error_log( sprintf(
					'[Saleson Product Creator] Product #%d created in SalesOn as #%d but failed to add to the RETAIL CUSTOMER price list: %s',
					$post_id,
					$saleson_product_id,
					is_array( $tier_result['error'] ) ? wp_json_encode( $tier_result['error'] ) : (string) $tier_result['error']
				) );
				set_transient(
					'saleson_create_error_' . $post_id,
					__( 'Product created in SalesOn, but could not be added to the Retail Customer price list yet - add it manually via SalesOn Pricing.', 'saleson-woo-sync' ),
					5 * MINUTE_IN_SECONDS
				);
			}
		}
	}
}
