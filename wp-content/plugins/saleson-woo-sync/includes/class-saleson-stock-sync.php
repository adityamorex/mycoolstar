<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Cron-driven pull of SalesOn stock/rate data into wp_saleson_stock_cache,
 * insert-only seeding of wp_saleson_product_map, and push of the cached
 * stock + RETAIL CUSTOMER rate onto already-matched WooCommerce products.
 *
 * Numeric SalesOn product id source (this matters for the Matcher/Pricing
 * work happening in parallel): confirmed in Phase 0 (see
 * phase0/findings.md, "Bulk pulls (rate-list / low-stock-summary)") that
 * NEITHER reports/rate-list NOR reports/low-stock-summary expose a numeric
 * id - only `name` / `product_code`. `GET products?with_unit=false` DOES
 * return the real numeric `id` for the entire catalog in a single,
 * non-paginated call (898/898 products in one shot, confirmed against
 * phase0/logs/saleson_products_full.json - it also matches the dashboard's
 * product_count and the rate-list total exactly). So that endpoint is used
 * here purely as the id/lookup catalog: we build a normalized-name => id
 * map from it, then resolve every reports/rate-list and
 * reports/low-stock-summary row against that map by normalized name.
 */
class Saleson_Stock_Sync {

	const CRON_HOOK    = 'saleson_woo_sync_cron';
	const CRON_INTERVAL_KEY = 'saleson_interval';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), self::CRON_INTERVAL_KEY, self::CRON_HOOK );
		}

		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Registers a custom "every N minutes" schedule since WP core has no
	 * built-in interval that matches saleson_cron_interval_minutes (default 15).
	 */
	public static function register_cron_schedule( $schedules ) {
		$minutes = (int) get_option( 'saleson_cron_interval_minutes', 15 );
		if ( $minutes < 1 ) {
			$minutes = 15;
		}

		$schedules[ self::CRON_INTERVAL_KEY ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: number of minutes between syncs */
			'display'  => sprintf( __( 'Every %d minutes (SalesOn sync)', 'saleson-woo-sync' ), $minutes ),
		);

		return $schedules;
	}

	const LOCK_KEY = 'saleson_woo_sync_running_lock';

	/**
	 * Main cron entry point. Never lets an API failure or unexpected shape
	 * become a PHP fatal - always finishes the log row, even on error.
	 *
	 * Locked against overlapping runs: this hosting plan's WP-Cron is
	 * page-load-triggered, not a real system clock (confirmed Phase 0), and a
	 * slow run (this cron takes several seconds against ~900 products) can
	 * still be mid-flight when a page load or a manual "Run Sync Now" click
	 * fires a second, overlapping execution. Kept as a defensive measure even
	 * though the actual 508-vs-514 price-flapping bug traced on 2026-07-28
	 * turned out to be a different, non-concurrency issue: multiple curated
	 * SalesOn items had been matched to the same single WooCommerce product
	 * (a many-to-one mapping collision from the original name-based
	 * reconciliation), so each push wrote a different, individually-correct
	 * price onto the same shared listing. Fixed by resolving the collisions
	 * in wp_saleson_product_map (see class-saleson-matcher-page.php's
	 * "Collisions" tab), not by anything in this file.
	 */
	public static function run() {
		if ( get_transient( self::LOCK_KEY ) ) {
			$skip_log_id = Saleson_Logger::start( 'stock_price_pull' );
			Saleson_Logger::finish( $skip_log_id, 0, 1, 'Skipped - another sync run is already in progress (lock held).' );
			return;
		}
		set_transient( self::LOCK_KEY, time(), 5 * MINUTE_IN_SECONDS ); // safety expiry in case of a fatal that skips the cleanup below

		$log_id    = Saleson_Logger::start( 'stock_price_pull' );
		$processed = 0;

		try {
			$api = new Saleson_API();

			$name_to_product = self::fetch_id_catalog( $api );
			$rate_list       = self::fetch_paginated( $api, 'reports/rate-list' );
			$low_stock       = self::fetch_paginated( $api, 'reports/low-stock-summary' );

			$low_stock_by_name = array();
			foreach ( $low_stock as $row ) {
				if ( empty( $row['name'] ) ) {
					continue;
				}
				$low_stock_by_name[ self::normalize_name( $row['name'] ) ] = $row;
			}

			global $wpdb;
			$now        = current_time( 'mysql' );
			$cache_table = $wpdb->prefix . 'saleson_stock_cache';

			foreach ( $rate_list as $row ) {
				if ( empty( $row['name'] ) ) {
					continue;
				}
				$norm = self::normalize_name( $row['name'] );

				if ( ! isset( $name_to_product[ $norm ] ) ) {
					// No numeric id resolvable for this row - can't safely
					// cache it (PK is the numeric id). Skip, don't fatal.
					continue;
				}

				$product    = $name_to_product[ $norm ];
				$saleson_id = (int) $product['id'];

				// Prefer the low-stock-summary figure when this row is one
				// of the (small) set of currently-low-stock products, since
				// that report is the more targeted/fresher of the two for
				// stock levels; otherwise fall back to the catalog's own
				// `stock` field from GET /products.
				if ( isset( $low_stock_by_name[ $norm ]['stock'] ) ) {
					$stock = (int) round( (float) $low_stock_by_name[ $norm ]['stock'] );
				} elseif ( isset( $product['stock'] ) ) {
					$stock = (int) round( (float) $product['stock'] );
				} else {
					$stock = 0;
				}

				$product_code = '';
				if ( ! empty( $row['product_code'] ) ) {
					$product_code = $row['product_code'];
				} elseif ( ! empty( $product['product_code'] ) ) {
					$product_code = $product['product_code'];
				}

				$group_name = isset( $product['group_id'] ) && $product['group_id'] !== '' ? (string) $product['group_id'] : null;
				$brand      = isset( $product['brand_id'] ) && $product['brand_id'] !== '' ? (string) $product['brand_id'] : null;
				// Fallback for the ~18 curated items confirmed (2026-07-28) to have
				// no entry at all in the RETAIL CUSTOMER price list - the product's
				// own sell_price is used instead so these don't sit unpriced/stale.
				$sell_price = isset( $product['sell_price'] ) && $product['sell_price'] !== '' ? (float) $product['sell_price'] : null;

				$wpdb->replace(
					$cache_table,
					array(
						'saleson_product_id' => $saleson_id,
						'name'               => sanitize_text_field( $row['name'] ),
						'product_code'       => $product_code !== '' ? sanitize_text_field( $product_code ) : null,
						'group_name'         => $group_name ? sanitize_text_field( $group_name ) : null,
						'brand'              => $brand ? sanitize_text_field( $brand ) : null,
						'stock'              => $stock,
						'sell_price'         => $sell_price,
						'last_synced_at'     => $now,
					),
					array( '%d', '%s', '%s', '%s', '%s', '%d', '%f', '%s' )
				);

				self::ensure_product_map_row( $saleson_id );

				$processed++;
			}

			// Real bug found 2026-08-05: SalesOn products with state = "DRAFT"
			// (18 of the 220 curated items, confirmed) are silently absent from
			// both reports/rate-list and reports/low-stock-summary, so the loop
			// above never gives them a wp_saleson_stock_cache row at all - not a
			// timing lag, a permanent gap, since push_to_matched_woo_products()
			// below only pushes stock for rows that already have a cache entry
			// (INNER JOIN). Pricing is unaffected (products/price-list/{id}
			// doesn't filter by state), only stock. Close the gap by giving
			// every curated product a cache row directly from the id catalog's
			// own `stock` field (which DOES include DRAFT-state items), for any
			// curated SalesOn id the rate-list loop above didn't already cover.
			self::backfill_stock_cache_for_linked( $name_to_product, $cache_table, $now );

			// Same cadence: price tiers, logged as its own run.
			Saleson_Price_Sync_Pull::pull_all_tiers();

			// Same cadence: party credit/balance refresh, logged as its own run.
			Saleson_Party_Balance_Sync::run();

			// Same cadence: mirror each submitted order's real SalesOn status
			// onto its WooCommerce order, logged as its own run.
			Saleson_Order_Status_Sync::run();

			// Same cadence: import new SalesOn orders (offline/phone/ERP direct)
			// into WooCommerce so all orders are visible in wp-admin.
			Saleson_Order_Importer::sync_from_saleson();

			// Same cadence: give genuinely new SalesOn products a draft listing
			// on the website, so staff only have to add a photo and publish.
			// Runs AFTER the stock/price pull above so a newly imported product
			// already has its cache row to read stock and price from.
			Saleson_Product_Importer::auto_import_new();

			// Push the freshly-cached stock/price onto Woo products that are
			// already matched, so the storefront reflects this run immediately.
			$push_result = self::push_to_matched_woo_products();

			if ( ! empty( $push_result['mismatches'] ) ) {
				Saleson_Logger::finish( $log_id, $processed, count( $push_result['mismatches'] ), implode( ' | ', $push_result['mismatches'] ) );
			} else {
				Saleson_Logger::finish( $log_id, $processed, 0, null );
			}
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $processed, 1, $e->getMessage() );
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * GET products?with_unit=false - single call, whole catalog, has the
	 * real numeric SalesOn product id. Returns a normalized-name => product
	 * array map.
	 */
	/**
	 * warehouse_id is required here, not optional: confirmed empirically
	 * (2026-07-28) that GET /products without it returns a company-wide
	 * aggregate stock figure that can meaningfully differ from what's actually
	 * available at the real fulfillment warehouse (e.g. one product showed
	 * 87 available unscoped vs 0 available/20 committed when scoped to 14229 -
	 * stable and repeatable across multiple calls, not a timing fluke). Selling
	 * against the unscoped number risks overselling stock that isn't actually
	 * there to ship.
	 */
	private static function fetch_id_catalog( Saleson_API $api ) {
		$result = $api->get( 'products', array( 'with_unit' => 'false', 'warehouse_id' => Saleson_API::WAREHOUSE_ID ) );

		if ( ! $result['ok'] || empty( $result['data']['products'] ) || ! is_array( $result['data']['products'] ) ) {
			throw new \RuntimeException( 'Failed to fetch SalesOn product catalog: ' . ( $result['error'] ? $result['error'] : 'unknown error' ) );
		}

		$map = array();
		foreach ( $result['data']['products'] as $p ) {
			if ( empty( $p['id'] ) || empty( $p['name'] ) ) {
				continue;
			}
			$map[ self::normalize_name( $p['name'] ) ] = $p;
		}

		return $map;
	}

	/**
	 * Cursor-paginated pull for reports/rate-list and
	 * reports/low-stock-summary (page_size + opaque cursor, next page via
	 * page_info.next_cursor - confirmed shape in Phase 0).
	 */
	private static function fetch_paginated( Saleson_API $api, $path ) {
		$rows   = array();
		$cursor = null;
		$page   = 0;

		do {
			$params = array( 'page_size' => 100 );
			if ( $cursor ) {
				$params['cursor'] = $cursor;
			}

			$result = $api->get( $path, $params );
			if ( ! $result['ok'] ) {
				throw new \RuntimeException( "Failed to fetch {$path}: " . ( $result['error'] ? $result['error'] : 'unknown error' ) );
			}

			$data  = is_array( $result['data'] ) ? $result['data'] : array();
			$batch = isset( $data['reports'] ) && is_array( $data['reports'] ) ? $data['reports'] : array();
			$rows  = array_merge( $rows, $batch );

			$cursor = isset( $data['page_info']['next_cursor'] ) ? $data['page_info']['next_cursor'] : null;
			$page++;
		} while ( $cursor && ! empty( $batch ) && $page < 50 ); // safety valve

		return $rows;
	}

	private static function normalize_name( $name ) {
		$name = strtolower( trim( (string) $name ) );
		$name = preg_replace( '/[^a-z0-9\s]/', '', $name );
		$name = preg_replace( '/\s+/', ' ', $name );
		return trim( (string) $name );
	}

	/**
	 * Insert-if-missing only. Never touches mapping_status/woo_product_id
	 * on a row that already exists - the Matcher screen owns that data once set.
	 *
	 * `first_seen_at` is stamped on insert and never updated afterwards - it's
	 * what makes "this product is new in SalesOn" answerable on the Products
	 * console, and what Saleson_Product_Importer uses to decide which products
	 * to auto-create a website listing for (genuinely new ones) versus leave
	 * for staff to opt in (the pre-existing non-curated backlog).
	 */
	private static function ensure_product_map_row( $saleson_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';

		$exists = $wpdb->get_var(
			$wpdb->prepare( "SELECT saleson_product_id FROM {$table} WHERE saleson_product_id = %d", $saleson_id )
		);

		if ( $exists ) {
			return;
		}

		$wpdb->insert(
			$table,
			array(
				'saleson_product_id' => $saleson_id,
				'woo_product_id'     => null,
				'mapping_status'     => 'unmatched',
				'confidence'         => null,
				'source'             => 'stock_sync',
				'mapped_at'          => null,
				'listing_status'     => 'not_listed',
				'first_seen_at'      => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Closes the DRAFT-state gap documented above: gives every LINKED SalesOn
	 * product a wp_saleson_stock_cache row, even ones the main rate-list loop
	 * never saw (SalesOn omits DRAFT-state products from reports/rate-list
	 * entirely). Only touches ids missing from the cache - never overwrites a
	 * row the main loop just wrote this run (that data is fresher/more
	 * complete, e.g. product_code from the rate-list row itself).
	 *
	 * Scope widened 2026-08-20 from `is_curated = 1` to "linked to a website
	 * product", to match the push query's gate. These two MUST stay in sync:
	 * push_to_matched_woo_products() INNER JOINs the cache table, so a linked
	 * product with no cache row is silently skipped and never receives stock
	 * or price - which is exactly the bug that made website-created products
	 * un-orderable before this change.
	 *
	 * @param array  $name_to_product normalized-name => product array, from fetch_id_catalog()
	 * @param string $cache_table     wp_saleson_stock_cache table name
	 * @param string $now             current_time( 'mysql' ), shared with the main loop
	 */
	private static function backfill_stock_cache_for_linked( $name_to_product, $cache_table, $now ) {
		global $wpdb;
		$map_table = $wpdb->prefix . 'saleson_product_map';

		$curated_ids = $wpdb->get_col(
			"SELECT DISTINCT saleson_product_id FROM {$map_table}
			 WHERE mapping_status = 'matched' AND woo_product_id IS NOT NULL"
		);
		if ( empty( $curated_ids ) ) {
			return;
		}

		$id_to_product = array();
		foreach ( $name_to_product as $product ) {
			if ( ! empty( $product['id'] ) ) {
				$id_to_product[ (int) $product['id'] ] = $product;
			}
		}

		foreach ( $curated_ids as $saleson_id ) {
			$saleson_id = (int) $saleson_id;

			$already_cached = $wpdb->get_var(
				$wpdb->prepare( "SELECT saleson_product_id FROM {$cache_table} WHERE saleson_product_id = %d AND last_synced_at = %s", $saleson_id, $now )
			);
			if ( $already_cached ) {
				continue; // main loop already gave this one a fresh row this run
			}

			$product = isset( $id_to_product[ $saleson_id ] ) ? $id_to_product[ $saleson_id ] : null;
			if ( ! $product ) {
				continue; // not in SalesOn's catalog at all - nothing to backfill
			}

			$stock      = isset( $product['stock'] ) ? (int) round( (float) $product['stock'] ) : 0;
			$group_name = isset( $product['group_id'] ) && $product['group_id'] !== '' ? (string) $product['group_id'] : null;
			$brand      = isset( $product['brand_id'] ) && $product['brand_id'] !== '' ? (string) $product['brand_id'] : null;
			$sell_price = isset( $product['sell_price'] ) && $product['sell_price'] !== '' ? (float) $product['sell_price'] : null;

			$wpdb->replace(
				$cache_table,
				array(
					'saleson_product_id' => $saleson_id,
					'name'               => sanitize_text_field( isset( $product['name'] ) ? $product['name'] : '' ),
					'product_code'       => ! empty( $product['product_code'] ) ? sanitize_text_field( $product['product_code'] ) : null,
					'group_name'         => $group_name ? sanitize_text_field( $group_name ) : null,
					'brand'              => $brand ? sanitize_text_field( $brand ) : null,
					'stock'              => $stock,
					'sell_price'         => $sell_price,
					'last_synced_at'     => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%d', '%f', '%s' )
			);
		}
	}

	/**
	 * For every already-matched product_map row, push the cached stock and
	 * the RETAIL CUSTOMER (price list 1471) rate onto the linked WooCommerce
	 * product. Never fatals if WooCommerce isn't active or a product 404s.
	 */
	/**
	 * @return array{pushed:int, mismatches:array<int,string>} mismatches records
	 *         "$woo_id: expected X, got Y (post-save re-check)" whenever the price
	 *         we just wrote doesn't stick immediately - strong evidence something
	 *         else (another plugin hooking product save, e.g. an inventory/pricing
	 *         plugin recalculating from its own formula) is overwriting it in the
	 *         same request, since a plain silent failure here would otherwise be
	 *         invisible (no exception is thrown either way).
	 */
	private static function push_to_matched_woo_products() {
		if ( ! function_exists( 'wc_update_product_stock' ) || ! function_exists( 'wc_get_product' ) ) {
			return array( 'pushed' => 0, 'mismatches' => array() );
		}

		global $wpdb;
		$map_table   = $wpdb->prefix . 'saleson_product_map';
		$cache_table = $wpdb->prefix . 'saleson_stock_cache';
		$tiers_table = $wpdb->prefix . 'saleson_price_tiers';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// Gate is "is it linked to a website product", NOT is_curated.
				// Until 2026-08-20 this required is_curated = 1, which meant any
				// product added after the original Phase 1 catalog silently never
				// received stock or price - see Saleson_DB::migrate_listing_status()
				// for the full story. Deliberately NOT filtered by listing_status
				// either: a delisted product should keep syncing so it's already
				// correct the moment staff relist it.
				"SELECT m.woo_product_id, c.stock, t.rate AS retail_rate
				 FROM {$map_table} m
				 INNER JOIN {$cache_table} c ON c.saleson_product_id = m.saleson_product_id
				 LEFT JOIN {$tiers_table} t ON t.saleson_product_id = m.saleson_product_id AND t.price_list_id = %d
				 WHERE m.mapping_status = 'matched' AND m.woo_product_id IS NOT NULL",
				Saleson_API::PRICE_LIST_RETAIL
			),
			ARRAY_A
		);

		$pushed     = 0;
		$mismatches = array();

		if ( empty( $rows ) ) {
			return array( 'pushed' => 0, 'mismatches' => array() );
		}

		foreach ( $rows as $row ) {
			$woo_id = (int) $row['woo_product_id'];
			if ( ! $woo_id ) {
				continue;
			}

			$product = wc_get_product( $woo_id );
			if ( ! $product ) {
				continue;
			}

			// Real bug found 2026-08-05: wc_update_product_stock() only updates
			// the _stock quantity - it does NOT turn stock management on for a
			// product that never had it enabled (confirmed live: several
			// bulk-created products, whose stock was unknown at creation time
			// because of the DRAFT-state cache gap above, ended up with
			// manage_stock=false forever, showing a generic "In Stock" label
			// with no real quantity and no cart-quantity enforcement, even
			// after this push ran). Explicitly enable it first.
			if ( ! $product->get_manage_stock() ) {
				$product->set_manage_stock( true );
				$product->save();
				$product = wc_get_product( $woo_id ); // reload after the save
			}

			wc_update_product_stock( $product, (int) $row['stock'], 'set' );
			$pushed++;

			$has_retail_price = isset( $row['retail_rate'] ) && $row['retail_rate'] !== null && $row['retail_rate'] !== '';

			// Option A (client decision, 2026-07-28): when SalesOn has no RETAIL
			// CUSTOMER price for this item (confirmed: ~16 curated items are
			// deliberately wholesale-only, priced for Dealer/Distributor/Supermart
			// but not Retail - see findings.md), clear the price rather than
			// leaving whatever stale value happened to be there before - WooCommerce
			// then falls back to its own "no price set" / non-purchasable display,
			// which reads as unavailable-to-retail rather than a guessed number.
			if ( ! $has_retail_price ) {
				$product = wc_get_product( $woo_id );
				if ( $product && '' !== $product->get_regular_price() ) {
					$product->set_regular_price( '' );
					$product->set_sale_price( '' );
					$product->save();
				}
			}

			if ( $has_retail_price ) {
				$expected = number_format( (float) $row['retail_rate'], 2, '.', '' );

				$product = wc_get_product( $woo_id ); // reload post-stock-update
				if ( $product ) {
					$product->set_regular_price( $expected );
					$product->save();
				}

				// Bypass any object cache and read the raw stored value straight
				// back from the DB, in the same request, to catch anything that
				// silently reverted it (a duplicate mapping collision, another
				// plugin's own save hook, etc. - see class-saleson-matcher-page.php's
				// Collisions tab, which is the real fix for the collision case).
				clean_post_cache( $woo_id );
				$actual = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price'",
						$woo_id
					)
				);
				$actual_norm = null !== $actual ? number_format( (float) $actual, 2, '.', '' ) : null;

				if ( $actual_norm !== $expected ) {
					$mismatches[] = sprintf(
						'woo_product_id %d: tried to set regular_price to %s, but it reads back as %s immediately after save',
						$woo_id,
						$expected,
						null === $actual_norm ? '(none)' : $actual_norm
					);
				}
			}
		}

		return array( 'pushed' => $pushed, 'mismatches' => $mismatches );
	}
}
