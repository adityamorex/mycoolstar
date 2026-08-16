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

			foreach ( $result['data']['parties'] as $party ) {
				$party_id = isset( $party['id'] ) ? (int) $party['id'] : 0;
				if ( ! $party_id ) {
					continue;
				}

				$updated = $wpdb->update(
					$table,
					array(
						'credit_limit'   => isset( $party['credit_limit'] ) ? (float) $party['credit_limit'] : null,
						'credit_period'  => isset( $party['credit_period'] ) ? (int) $party['credit_period'] : null,
						'amount_balance' => isset( $party['amount_balance'] ) ? (float) $party['amount_balance'] : null,
						'last_synced_at' => $now,
					),
					array( 'saleson_party_id' => $party_id ),
					array( '%f', '%d', '%f', '%s' ),
					array( '%d' )
				);

				if ( false === $updated ) {
					$errors++;
					continue;
				}
				if ( $updated > 0 ) {
					$processed++;
				}
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
