<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 2, step 6: keeps credit_limit / amount_balance fresh on
 * wp_saleson_party_map and on each linked WordPress account's usermeta.
 * Runs on the same 15-minute cadence as stock/pricing (called from
 * Saleson_Stock_Sync::run(), not its own separate cron hook) - a one-time
 * import at account creation goes stale immediately, same reasoning that
 * already drives the pricing/stock sync.
 *
 * Refreshes ONLY rows already in wp_saleson_party_map - never creates,
 * matches, or excludes a party (that's Saleson_Party_Importer's job, run
 * on demand from the SalesOn Parties admin screen). A brand-new SalesOn
 * party not yet in the map is silently skipped here; it'll appear on the
 * next manual "Run / Re-run Party Import".
 */
class Saleson_Party_Balance_Sync {

	public static function run() {
		$log_id    = Saleson_Logger::start( 'party_balance_sync' );
		$processed = 0;
		$errors    = 0;

		try {
			$api    = new Saleson_API();
			$result = $api->get( 'parties', array( 'page_size' => 5000 ) );

			if ( ! $result['ok'] || empty( $result['data']['parties'] ) ) {
				throw new \RuntimeException( 'Failed to fetch parties from SalesOn: ' . ( $result['error'] ? $result['error'] : 'unknown error' ) );
			}

			global $wpdb;
			$table = $wpdb->prefix . 'saleson_party_map';
			$now   = current_time( 'mysql' );

			// Batched CASE-WHEN bulk update, NOT one $wpdb->update() per party
			// (2026-09-02): with 2,779 real parties confirmed live, that was
			// 2,779 synchronous DB round-trips in a single PHP request - even
			// at a modest 15-40ms each on shared hosting, easily 40-100+
			// seconds on its own, with nothing to do with the SalesOn API
			// (confirmed separately: the API call itself takes ~2.5s). This
			// is very likely what was actually behind Saleson_Stock_Sync's
			// lock dying silently right after price_tiers (the step
			// immediately before this one). CASE-WHEN keeps this UPDATE-only
			// (never INSERT) so a party not already in the map still can't
			// be created here, matching this class's existing contract.
			$rows = array();
			foreach ( $result['data']['parties'] as $party ) {
				$party_id = isset( $party['id'] ) ? (int) $party['id'] : 0;
				if ( ! $party_id ) {
					continue;
				}
				$rows[ $party_id ] = array(
					'credit_limit'   => isset( $party['credit_limit'] ) ? (float) $party['credit_limit'] : 0,
					'credit_period'  => isset( $party['credit_period'] ) ? (int) $party['credit_period'] : 0,
					'amount_balance' => isset( $party['amount_balance'] ) ? (float) $party['amount_balance'] : 0,
				);
			}

			$chunk_size = 300;
			$chunks     = array_chunk( $rows, $chunk_size, true );

			foreach ( $chunks as $chunk ) {
				$ids = array_keys( $chunk );
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

				$credit_limit_case   = 'credit_limit = CASE saleson_party_id ';
				$credit_period_case  = 'credit_period = CASE saleson_party_id ';
				$amount_balance_case = 'amount_balance = CASE saleson_party_id ';
				$case_params         = array();

				foreach ( $chunk as $party_id => $vals ) {
					$credit_limit_case   .= 'WHEN %d THEN %f ';
					$credit_period_case  .= 'WHEN %d THEN %d ';
					$amount_balance_case .= 'WHEN %d THEN %f ';
					$case_params[]        = array( $party_id, $vals['credit_limit'], $party_id, $vals['credit_period'], $party_id, $vals['amount_balance'] );
				}

				// Flatten case_params in the same order the three CASE blocks
				// are concatenated below: all credit_limit pairs, then all
				// credit_period pairs, then all amount_balance pairs.
				$flat_params = array();
				foreach ( $case_params as $p ) {
					$flat_params[] = $p[0];
					$flat_params[] = $p[1];
				}
				foreach ( $case_params as $p ) {
					$flat_params[] = $p[2];
					$flat_params[] = $p[3];
				}
				foreach ( $case_params as $p ) {
					$flat_params[] = $p[4];
					$flat_params[] = $p[5];
				}

				$sql = "UPDATE {$table} SET "
					. $credit_limit_case . 'END, '
					. $credit_period_case . 'END, '
					. $amount_balance_case . 'END, '
					. 'last_synced_at = %s '
					. "WHERE saleson_party_id IN ({$placeholders})";

				$params = array_merge( $flat_params, array( $now ), $ids );

				$result_rows = $wpdb->query( $wpdb->prepare( $sql, $params ) );

				if ( false === $result_rows ) {
					$errors += count( $chunk );
					continue;
				}
				$processed += count( $chunk );
			}

			self::push_to_wp_users();

			Saleson_Logger::finish( $log_id, $processed, $errors, null );
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $processed, 1, $e->getMessage() );
		}

		return $processed;
	}

	/**
	 * Mirrors the freshly-updated credit_limit/amount_balance onto usermeta
	 * for every party that already has a live WordPress account (matched or
	 * created) - ready for the future dealer-facing balance display (Phase 2,
	 * step 7, not built yet), so that UI has real data to read from day one
	 * instead of needing its own separate sync built later.
	 */
	private static function push_to_wp_users() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_party_map';

		$rows = $wpdb->get_results(
			"SELECT woo_user_id, credit_limit, credit_period, amount_balance
			 FROM {$table}
			 WHERE woo_user_id IS NOT NULL AND mapping_status IN ( 'matched', 'created' )"
		);

		$now = current_time( 'mysql' );

		foreach ( $rows as $row ) {
			$user_id = (int) $row->woo_user_id;
			if ( ! $user_id ) {
				continue;
			}
			update_user_meta( $user_id, 'credit_limit', $row->credit_limit );
			update_user_meta( $user_id, 'credit_period', $row->credit_period );
			update_user_meta( $user_id, 'amount_balance', $row->amount_balance );
			update_user_meta( $user_id, 'balance_last_synced_at', $now );
		}
	}
}
