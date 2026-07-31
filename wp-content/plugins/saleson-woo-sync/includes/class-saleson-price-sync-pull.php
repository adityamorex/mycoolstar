<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pulls all 4 SalesOn customer-group price lists (DISTRIBUTORS, RETAIL
 * CUSTOMER, DEALER, SUPERMART) and upserts wp_saleson_price_tiers.
 *
 * Unlike reports/rate-list and reports/low-stock-summary, GET
 * products/price-list/{id} already returns a real numeric `product_id` on
 * every row (confirmed Phase 0, e.g.
 * phase0/logs/20260727T142247484188_audit_pricelist_RETAIL CUSTOMER.json),
 * so no cross-reference against GET /products is needed here - this is a
 * direct upsert.
 *
 * Note on the `group_id`/`group_name` columns: Phase 0 confirmed SalesOn has
 * no formal link between a customer group (parties/groups) and a price
 * list - every group's own record shows price_list_id: null, and the
 * pairing only holds by name convention (e.g. group "DEALER" <-> price list
 * "DEALER"). Rather than fabricate a link, `group_id` here stores the price
 * list's own id and `group_name` stores the price list's own name - the
 * only pairing that's actually reliable today.
 */
class Saleson_Price_Sync_Pull {

	const PRICE_LISTS = array(
		Saleson_API::PRICE_LIST_DISTRIBUTORS,
		Saleson_API::PRICE_LIST_RETAIL,
		Saleson_API::PRICE_LIST_DEALER,
		Saleson_API::PRICE_LIST_SUPERMART,
	);

	/**
	 * Pulls and upserts all 4 tiers. Catches its own errors and writes its
	 * own 'price_tiers_pull' log row so a failure here never fatals the
	 * caller (Saleson_Stock_Sync::run(), same cron cadence).
	 *
	 * @return int number of price-tier rows processed
	 */
	public static function pull_all_tiers() {
		$log_id    = Saleson_Logger::start( 'price_tiers_pull' );
		$processed = 0;

		try {
			$api = new Saleson_API();
			global $wpdb;
			$now   = current_time( 'mysql' );
			$table = $wpdb->prefix . 'saleson_price_tiers';

			foreach ( self::PRICE_LISTS as $price_list_id ) {
				$result = $api->get( "products/price-list/{$price_list_id}" );

				if ( ! $result['ok'] ) {
					throw new \RuntimeException( "Failed to fetch price list {$price_list_id}: " . ( $result['error'] ? $result['error'] : 'unknown error' ) );
				}

				$list       = isset( $result['data']['price_list'] ) && is_array( $result['data']['price_list'] ) ? $result['data']['price_list'] : array();
				$group_name = isset( $list['name'] ) ? (string) $list['name'] : null;
				$products   = isset( $list['products'] ) && is_array( $list['products'] ) ? $list['products'] : array();

				foreach ( $products as $row ) {
					if ( ! isset( $row['product_id'] ) ) {
						continue;
					}

					$wpdb->replace(
						$table,
						array(
							'saleson_product_id' => (int) $row['product_id'],
							'price_list_id'      => (int) $price_list_id,
							'group_id'           => (int) $price_list_id,
							'group_name'         => $group_name ? sanitize_text_field( $group_name ) : null,
							'rate'               => isset( $row['rate'] ) ? (float) $row['rate'] : null,
							'last_synced_at'     => $now,
						),
						array( '%d', '%d', '%d', '%s', '%f', '%s' )
					);

					$processed++;
				}
			}

			Saleson_Logger::finish( $log_id, $processed, 0, null );
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $processed, 1, $e->getMessage() );
		}

		return $processed;
	}
}
