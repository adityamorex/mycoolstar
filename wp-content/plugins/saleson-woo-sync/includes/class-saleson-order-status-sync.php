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

	const META_STATUS_CHECKED_AT = '_saleson_status_checked_at';

	public static function run() {
		$log_id       = Saleson_Logger::start( 'order_status_sync' );
		$processed    = 0;
		$errors       = 0;
		$error_detail = array(); // collected so a FAILED cycle actually says why, not just "1 error"

		try {
			global $wpdb;

			// Terminal statuses never change again in SalesOn's lifecycle
			// (Delivered, Cancelled), so excluding them here is what keeps
			// this step's cost bounded as order count grows - checking EVERY
			// tracked order EVERY cycle with no cap (as this did until
			// 2026-09-01) meant one API call per order, unconditionally,
			// forever, and became unsustainable the moment the historical
			// backfill pushed the tracked order count into the hundreds:
			// a single cycle started taking longer than the cron interval,
			// so Saleson_Stock_Sync's own self-lock (a real safety feature)
			// began skipping most ticks with "another sync run is already in
			// progress" - a livelock, not a crash, but just as stuck.
			// LIMIT below is a hard backstop on top of the exclusion, in case
			// the active (non-terminal) set itself ever grows past what one
			// cron cycle can process in time.
			$terminal = array( 'wc-saleson-delivered', 'wc-cancelled' );
			$placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

			// Ordered by least-recently-checked first (NULLs - never checked
			// at all - sort first in MySQL ASC), not left unordered: with
			// more non-terminal tracked orders than the 150 cap (very
			// plausible once history is backfilled), an unordered LIMIT can
			// return the SAME subset every single cycle, starving the rest
			// indefinitely. Confirmed live 2026-09-02: a real invoiced order
			// went 24+ hours with date_modified never once changing, despite
			// other orders processing successfully every cycle. checked_at
			// is stamped on every order this loop examines below, regardless
			// of outcome, so coverage now rotates through the full set.
			$order_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 LEFT JOIN {$wpdb->postmeta} checked ON checked.post_id = pm.post_id AND checked.meta_key = %s
				 WHERE pm.meta_key = %s AND p.post_status NOT IN ({$placeholders})
				 ORDER BY checked.meta_value ASC
				 LIMIT 150",
				array_merge( array( self::META_STATUS_CHECKED_AT, Saleson_Order_Submitter::META_TRANSACTION_ID ), $terminal )
			) );

			$hpos_table = $wpdb->prefix . 'wc_orders_meta';
			$hpos_orders_table = $wpdb->prefix . 'wc_orders';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
				$hpos_ids = $wpdb->get_col( $wpdb->prepare(
					"SELECT DISTINCT wom.order_id FROM {$hpos_table} wom
					 INNER JOIN {$hpos_orders_table} o ON o.id = wom.order_id
					 LEFT JOIN {$hpos_table} checked ON checked.order_id = wom.order_id AND checked.meta_key = %s
					 WHERE wom.meta_key = %s AND o.status NOT IN ({$placeholders})
					 ORDER BY checked.meta_value ASC
					 LIMIT 150",
					array_merge( array( self::META_STATUS_CHECKED_AT, Saleson_Order_Submitter::META_TRANSACTION_ID ), $terminal )
				) );
				if ( ! empty( $hpos_ids ) ) {
					$order_ids = array_unique( array_merge( $order_ids, $hpos_ids ) );
				}
			}

			// Backstop cap across the merged set too, in case both queries
			// each returned close to their own 150-row limit.
			$order_ids = array_slice( $order_ids, 0, 150 );

			$api = new Saleson_API();

			// One bulk call instead of one-per-order: the list endpoint's
			// summary already includes each order's `status` directly (just
			// not its invoice `association`, which still needs the detail
			// endpoint - but only orders that actually need that get one).
			// page_size=200 comfortably covers the 150-row tracked-order cap
			// above, since active (non-terminal) SalesOn orders are, in
			// practice, near-always among its most recent ones.
			$bulk_status_by_txn = array();
			$list_result        = $api->get( 'transactions/sales-order', array( 'page_size' => 200 ) );
			if ( ! empty( $list_result['ok'] ) && ! empty( $list_result['data']['invoices'] ) ) {
				foreach ( $list_result['data']['invoices'] as $row ) {
					if ( ! empty( $row['id'] ) && isset( $row['status'] ) ) {
						$bulk_status_by_txn[ (int) $row['id'] ] = $row['status'];
					}
				}
			} else {
				// Every tracked order will fall through to the per-order
				// fallback call below - not fatal, but worth recording since
				// it's the difference between "1 quick bulk call" and "up to
				// 150 individual ones" for this cycle.
				$error_detail[] = 'Bulk list call failed or returned no invoices (' . ( $list_result['error'] ?? 'no error detail from API wrapper' ) . ') - every order fell back to individual detail calls this cycle.';
			}

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order ) {
					continue;
				}

				// $order->get_meta() is the HPOS-correct read - but every order
				// submitted BEFORE the 2026-09-01 fix has its transaction id
				// sitting only in the legacy wp_postmeta table (which is why
				// the discovery query above still unions both tables), so
				// get_meta() alone would find nothing for those and silently
				// stop syncing their status. Fall back to the legacy read,
				// and if that's where it's found, re-save it onto the order
				// properly so it self-heals into wc_orders_meta the first
				// time this runs for it - a one-time migration spread across
				// however many cron cycles it takes to touch every old order.
				$transaction_id = $order->get_meta( Saleson_Order_Submitter::META_TRANSACTION_ID );
				if ( ! $transaction_id ) {
					$legacy_id = get_post_meta( $order_id, Saleson_Order_Submitter::META_TRANSACTION_ID, true );
					if ( $legacy_id ) {
						$transaction_id = $legacy_id;
						$legacy_no      = get_post_meta( $order_id, Saleson_Order_Submitter::META_TRANSACTION_NO, true );
						$order->update_meta_data( Saleson_Order_Submitter::META_TRANSACTION_ID, $legacy_id );
						if ( $legacy_no ) {
							$order->update_meta_data( Saleson_Order_Submitter::META_TRANSACTION_NO, $legacy_no );
						}
						$order->save();
					}
				}
				if ( ! $transaction_id ) {
					continue;
				}

				$saleson_status = isset( $bulk_status_by_txn[ (int) $transaction_id ] ) ? $bulk_status_by_txn[ (int) $transaction_id ] : null;

				// Not in the bulk list's first 200 - a rare case (a very old
				// order that's somehow still non-terminal). Fall back to the
				// one-off detail call rather than leaving it unsynced forever.
				$detail_fetched = null;
				if ( null === $saleson_status ) {
					$fallback = $api->get( 'transactions/sales-order/' . (int) $transaction_id );
					if ( empty( $fallback['ok'] ) || empty( $fallback['data']['invoice']['status'] ) ) {
						$errors++;
						$error_detail[] = sprintf(
							'Order #%d (txn %s): %s',
							$order_id,
							$transaction_id,
							$fallback['error'] ?? 'detail call returned no status'
						);
						continue;
					}
					$detail_fetched = $fallback['data']['invoice'];
					$saleson_status = $detail_fetched['status'];
				}

				// Stamped regardless of what happens next (unknown status,
				// no change needed, etc.) - this is what the discovery query
				// above rotates on, so every tracked order gets its turn
				// instead of a fixed subset being checked forever while the
				// rest starve.
				$order->update_meta_data( self::META_STATUS_CHECKED_AT, current_time( 'mysql' ) );
				$order->save();

				$target_status = isset( self::STATUS_MAP[ $saleson_status ] ) ? self::STATUS_MAP[ $saleson_status ] : null;

				if ( ! $target_status ) {
					// Unknown/unexpected status value from SalesOn - skip rather
					// than guess at a mapping, so it doesn't silently misfile.
					continue;
				}

				if ( $order->get_status() !== $target_status ) {
					$order->set_status( $target_status, __( 'Status synced from SalesOn.', 'saleson-woo-sync' ) );
					$order->save();
				}

				// Invoice sync needs the `association` field, which only the
				// per-order detail endpoint has - but only fetch it for
				// orders that could plausibly have an invoice by now (skips
				// the API call entirely for every order still Pending/Onhold/
				// Confirmed, which is most of them) AND don't already have
				// one synced.
				$invoice_relevant_statuses = array( 'Invoiced', 'Dispatched', 'Delivered' );
				if ( in_array( $saleson_status, $invoice_relevant_statuses, true ) && ! $order->get_meta( self::META_INVOICE_ID ) ) {
					if ( null === $detail_fetched ) {
						$detail = $api->get( 'transactions/sales-order/' . (int) $transaction_id );
						if ( ! empty( $detail['ok'] ) && ! empty( $detail['data']['invoice'] ) ) {
							$detail_fetched = $detail['data']['invoice'];
						}
					}
					if ( null !== $detail_fetched ) {
						self::maybe_sync_invoice( $order, $detail_fetched, $api );
					}
				}

				$processed++;
			}

			Saleson_Logger::finish( $log_id, $processed, $errors, $error_detail ? implode( ' | ', $error_detail ) : null );
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
		if ( $order->get_meta( self::META_INVOICE_ID ) ) {
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

		// $order->update_meta_data()+save(), NOT update_post_meta(): this site
		// runs HPOS, where order meta lives in wc_orders_meta, not wp_postmeta.
		// update_post_meta() was writing here silently succeeding but into the
		// wrong table - every reader of this data (the meta box, the customer
		// view, the Orders-list Invoice column) uses $order->get_meta(), which
		// is HPOS-aware, so the invoice data never actually appeared anywhere
		// despite the sync itself reporting success (found 2026-09-01, tracing
		// why invoiced orders showed no invoice number in Orders list).
		$order->update_meta_data( self::META_INVOICE_ID, $invoice_id );
		$order->update_meta_data( self::META_INVOICE_NO, sanitize_text_field( $invoice['transaction_no'] ?? '' ) );
		$order->update_meta_data( self::META_INVOICE_AMOUNT, isset( $invoice['amount'] ) ? (float) $invoice['amount'] : 0 );
		$order->update_meta_data( self::META_INVOICE_PAID, isset( $invoice['paid'] ) ? (float) $invoice['paid'] : 0 );
		$order->update_meta_data( self::META_INVOICE_DUE, isset( $invoice['amount_due'] ) ? (float) $invoice['amount_due'] : 0 );
		$order->update_meta_data( self::META_INVOICE_URL, isset( $invoice['public_url'] ) ? esc_url_raw( $invoice['public_url'] ) : '' );
		$order->save();

		$order->add_order_note( sprintf(
			/* translators: %s: SalesOn invoice number */
			__( 'Invoice generated in SalesOn: %s', 'saleson-woo-sync' ),
			$invoice['transaction_no'] ?? $invoice_id
		) );
	}
}
