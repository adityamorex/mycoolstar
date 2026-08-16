<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 3: mirrors each submitted order's real SalesOn status onto its
 * WooCommerce order status. Runs on the same 15-minute cadence as
 * stock/pricing/balance (called from Saleson_Stock_Sync::run()), not its
 * own cron hook - same established pattern as Saleson_Party_Balance_Sync.
 *
 * Client decision (2026-08-16): the website does not manage its own status
 * transitions - SalesOn is the single source of truth, this class only ever
 * copies SalesOn's status onto the order, never the other way round.
 */
class Saleson_Order_Status_Sync {

	/**
	 * SalesOn status value => WooCommerce order status slug WITHOUT the
	 * 'wc-' prefix (that's what wc_get_order()->set_status() expects; the
	 * 'wc-' prefix is only how it's stored in post_status). The 6 non-native
	 * ones were registered live 2026-08-16 via "Custom Order Status Manager"
	 * (confirmed via the orders endpoint's OPTIONS schema) - Cancelled reuses
	 * WooCommerce's native status, no custom registration needed for it.
	 *
	 * Note: 'saleson-dispatche' (missing the final 'd') is not a typo here -
	 * the status-manager plugin enforces a 17-character slug limit, so
	 * "saleson-dispatched" (18 chars) had to be truncated. Matches what's
	 * actually registered live, confirmed via the API.
	 */
	const STATUS_MAP = array(
		'Pending'    => 'saleson-pending',
		'Onhold'     => 'saleson-onhold',
		'Confirmed'  => 'saleson-confirmed',
		'Invoiced'   => 'saleson-invoiced',
		'Dispatched' => 'saleson-dispatche',
		'Delivered'  => 'saleson-delivered',
		'Cancelled'  => 'cancelled',
	);

	public static function run() {
		$log_id    = Saleson_Logger::start( 'order_status_sync' );
		$processed = 0;
		$errors    = 0;

		try {
			global $wpdb;
			$order_ids = $wpdb->get_col(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . Saleson_Order_Submitter::META_TRANSACTION_ID . "'"
			);

			$api = new Saleson_API();

			foreach ( $order_ids as $order_id ) {
				$transaction_id = get_post_meta( $order_id, Saleson_Order_Submitter::META_TRANSACTION_ID, true );
				if ( ! $transaction_id ) {
					continue;
				}

				$result = $api->get( 'transactions/sales-order/' . (int) $transaction_id );
				if ( empty( $result['ok'] ) || empty( $result['data']['invoice']['status'] ) ) {
					$errors++;
					continue;
				}

				$saleson_status = $result['data']['invoice']['status'];
				$target_status  = isset( self::STATUS_MAP[ $saleson_status ] ) ? self::STATUS_MAP[ $saleson_status ] : null;

				if ( ! $target_status ) {
					// Unknown/unexpected status value from SalesOn - skip rather
					// than guess at a mapping, so it doesn't silently misfile.
					continue;
				}

				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}

				if ( $order->get_status() !== $target_status ) {
					$order->set_status( $target_status, __( 'Status synced from SalesOn.', 'saleson-woo-sync' ) );
					$order->save();
				}

				$processed++;
			}

			Saleson_Logger::finish( $log_id, $processed, $errors, null );
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $processed, 1, $e->getMessage() );
		}

		return $processed;
	}
}
