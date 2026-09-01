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

			global $wpdb;
			$order_ids = $wpdb->get_col(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . Saleson_Order_Submitter::META_TRANSACTION_ID . "'"
			);

			$hpos_table = $wpdb->prefix . 'wc_orders_meta';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
				$hpos_ids = $wpdb->get_col(
					"SELECT DISTINCT order_id FROM {$hpos_table} WHERE meta_key = '" . Saleson_Order_Submitter::META_TRANSACTION_ID . "'"
				);
				if ( ! empty( $hpos_ids ) ) {
					$order_ids = array_unique( array_merge( $order_ids, $hpos_ids ) );
				}
			}

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

				// Phase 4: once SalesOn has generated an invoice for this order,
				// pull it in. Reuses the order-detail response already fetched
				// above (association is included there) rather than a separate
				// pass - only makes the ONE extra call per order that actually
				// needs it (most orders have no invoice yet).
				self::maybe_sync_invoice( $order, $result['data']['invoice'], $api );

				$processed++;
			}

			Saleson_Logger::finish( $log_id, $processed, $errors, null );
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $processed, 1, $e->getMessage() );
		}

		return $processed;
	}

	const META_INVOICE_ID     = '_saleson_invoice_id';
	const META_INVOICE_NO     = '_saleson_invoice_no';
	const META_INVOICE_AMOUNT = '_saleson_invoice_amount';
	const META_INVOICE_PAID   = '_saleson_invoice_paid';
	const META_INVOICE_DUE    = '_saleson_invoice_due';
	const META_INVOICE_URL    = '_saleson_invoice_url';

	/**
	 * Phase 4: a Sales Order's `association` field links to its Sales Invoice
	 * once SalesOn has generated one (confirmed live 2026-08-20 on a real
	 * order: association -> {"Sales Invoice": [{id, transaction_no}]}). Pulls
	 * the invoice's amount/paid/due and its public_url (a real print-ready
	 * invoice page - confirmed live it's HTML with proper @media print
	 * styling, not a downloadable file, so linking it directly is the honest
	 * plan rather than building server-side PDF generation).
	 *
	 * Only fetches the invoice detail once - re-checks are cheap (just
	 * reading the association field already in hand) but the invoice itself,
	 * once generated, doesn't need refetching every cycle.
	 */
	public static function maybe_sync_invoice( $order, $saleson_order, $api ) {
		if ( get_post_meta( $order->get_id(), self::META_INVOICE_ID, true ) ) {
			return; // already synced
		}

		$invoices = isset( $saleson_order['association']['Sales Invoice'] )
			? $saleson_order['association']['Sales Invoice']
			: array();
		if ( empty( $invoices[0]['id'] ) ) {
			return; // no invoice generated yet
		}

		$invoice_id = (int) $invoices[0]['id'];
		$detail     = $api->get( 'transactions/sales-invoice/' . $invoice_id );
		if ( empty( $detail['ok'] ) || empty( $detail['data']['invoice'] ) ) {
			return; // will retry next cycle - no meta written yet, so not "already synced"
		}

		$invoice = $detail['data']['invoice'];

		update_post_meta( $order->get_id(), self::META_INVOICE_ID, $invoice_id );
		update_post_meta( $order->get_id(), self::META_INVOICE_NO, sanitize_text_field( $invoice['transaction_no'] ?? '' ) );
		update_post_meta( $order->get_id(), self::META_INVOICE_AMOUNT, isset( $invoice['amount'] ) ? (float) $invoice['amount'] : 0 );
		update_post_meta( $order->get_id(), self::META_INVOICE_PAID, isset( $invoice['paid'] ) ? (float) $invoice['paid'] : 0 );
		update_post_meta( $order->get_id(), self::META_INVOICE_DUE, isset( $invoice['amount_due'] ) ? (float) $invoice['amount_due'] : 0 );
		update_post_meta( $order->get_id(), self::META_INVOICE_URL, isset( $invoice['public_url'] ) ? esc_url_raw( $invoice['public_url'] ) : '' );

		$order->add_order_note( sprintf(
			/* translators: %s: SalesOn invoice number */
			__( 'Invoice generated in SalesOn: %s', 'saleson-woo-sync' ),
			$invoice['transaction_no'] ?? $invoice_id
		) );
	}
}
