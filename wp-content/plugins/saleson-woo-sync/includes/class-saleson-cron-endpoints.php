<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Splits what used to be one big Saleson_Stock_Sync::run() - catalog fetch,
 * two paginated reports, product push, price tiers, party balance, order
 * status/invoice sync, order import, product import, ALL in one PHP
 * request - into separately-triggerable, independent endpoints.
 *
 * Why (2026-09-02): hPanel's resource graphs showed CPU repeatedly pinned
 * at 100% and memory repeatedly hitting its 1024MB cap during sync runs,
 * causing wp-admin requests sharing the same account to 503. Splitting the
 * work across time - several small, fast requests instead of one large
 * one - is the actual fix; no amount of optimizing the code inside a
 * single request changes how much CPU/memory a shared-hosting account is
 * allotted at any one moment.
 *
 * Each step gets its own URL, protected by a shared secret token (NOT
 * WordPress auth - these are hit by a plain wget cron job with no
 * session), configured as separate cron jobs in hPanel instead of the
 * single wp-cron.php entry. Deliberately bypasses the normal WP template
 * load: matched requests run their one step and exit immediately, so this
 * really is one lightweight thing per request, not "the same total work,
 * just labeled differently."
 */
class Saleson_Cron_Endpoints {

	const TOKEN_OPTION = 'saleson_cron_endpoint_token';

	const STEPS = array(
		'stock-price'    => array( 'Saleson_Stock_Sync', 'run_stock_price_only' ),
		'party-balance'  => array( 'Saleson_Party_Balance_Sync', 'run' ),
		'order-status'   => array( 'Saleson_Order_Status_Sync', 'run' ),
		'order-import'   => array( 'Saleson_Order_Importer', 'sync_from_saleson' ),
		'product-import' => array( 'Saleson_Product_Importer', 'auto_import_new' ),
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle_request' ), 0 );
	}

	public static function get_token() {
		$token = get_option( self::TOKEN_OPTION );
		if ( ! $token ) {
			$token = wp_generate_password( 32, false );
			update_option( self::TOKEN_OPTION, $token, false );
		}
		return $token;
	}

	/**
	 * Builds the full URL for one step, for display on the Settings page -
	 * what the user actually pastes into an hPanel cron job's wget command.
	 */
	public static function get_endpoint_url( $step ) {
		return add_query_arg(
			array(
				'saleson_cron' => $step,
				'token'        => self::get_token(),
			),
			home_url( '/' )
		);
	}

	public static function maybe_handle_request() {
		if ( empty( $_GET['saleson_cron'] ) ) {
			return;
		}

		$step = sanitize_key( wp_unslash( $_GET['saleson_cron'] ) );
		if ( 'dispatch-all' !== $step && ! isset( self::STEPS[ $step ] ) ) {
			status_header( 404 );
			exit( 'unknown step' );
		}

		$provided_token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		if ( ! hash_equals( self::get_token(), $provided_token ) ) {
			status_header( 403 );
			exit( 'invalid token' );
		}

		if ( 'dispatch-all' === $step ) {
			self::dispatch_all();
			status_header( 200 );
			header( 'Content-Type: text/plain' );
			exit( 'ok: dispatched ' . count( self::STEPS ) . ' step(s)' );
		}

		call_user_func( self::STEPS[ $step ] );

		status_header( 200 );
		header( 'Content-Type: text/plain' );
		exit( "ok: {$step}" );
	}

	/**
	 * Fires each real step as its own non-blocking loopback request (the
	 * same pattern wp-cron.php itself uses to spawn background work) rather
	 * than calling them directly in this process. Added 2026-09-02 after
	 * the hosting plan turned out to cap the account at 2 cron jobs total -
	 * far short of the 5 separate hPanel entries the split-endpoint design
	 * assumed. This keeps the actual goal (each step isolated in its own
	 * process, not stacked into one big request) while needing only ONE
	 * hPanel cron job, hitting this one URL.
	 *
	 * Staggered with a short sleep between dispatches so the 5 background
	 * processes don't all start at the very same instant - spread over a
	 * few seconds, not one simultaneous burst, which would just recreate
	 * the CPU spike this whole redesign exists to avoid.
	 */
	private static function dispatch_all() {
		$first = true;
		foreach ( array_keys( self::STEPS ) as $step ) {
			if ( ! $first ) {
				sleep( 2 );
			}
			$first = false;

			wp_remote_get( self::get_endpoint_url( $step ), array(
				'timeout'   => 0.5,   // don't wait for the real work to finish
				'blocking'  => false, // fire-and-forget - this is what makes it a separate process
				'sslverify' => false,
			) );
		}
	}
}
