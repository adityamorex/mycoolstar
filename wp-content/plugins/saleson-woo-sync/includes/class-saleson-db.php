<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * All 4 Phase 1 tables, created/upgraded via dbDelta on activation.
 * mapping_status values: matched | unmatched | orphan | ignored
 */
class Saleson_DB {

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		$p = $wpdb->prefix;

		$sql = array();

		$sql[] = "CREATE TABLE {$p}saleson_stock_cache (
			saleson_product_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL DEFAULT '',
			product_code VARCHAR(100) DEFAULT NULL,
			group_name VARCHAR(255) DEFAULT NULL,
			brand VARCHAR(255) DEFAULT NULL,
			stock INT DEFAULT 0,
			sell_price DECIMAL(12,2) DEFAULT NULL,
			last_synced_at DATETIME DEFAULT NULL,
			PRIMARY KEY (saleson_product_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}saleson_price_tiers (
			saleson_product_id BIGINT UNSIGNED NOT NULL,
			price_list_id BIGINT UNSIGNED NOT NULL,
			group_id BIGINT UNSIGNED DEFAULT NULL,
			group_name VARCHAR(255) DEFAULT NULL,
			rate DECIMAL(12,2) DEFAULT NULL,
			last_synced_at DATETIME DEFAULT NULL,
			PRIMARY KEY (saleson_product_id, price_list_id)
		) {$charset_collate};";

		// `listing_status` (added 2026-08-20) is what decides whether a product is
		// on the storefront: not_listed | listed | delisted. It deliberately
		// replaces `is_curated` as the gate for SYNCING - see the note on
		// migrate_listing_status() below for why. `is_curated` is kept as a
		// historical record of the original Phase 1 220-product list, but no
		// longer controls whether a product receives stock/price updates.
		$sql[] = "CREATE TABLE {$p}saleson_product_map (
			saleson_product_id BIGINT UNSIGNED NOT NULL,
			woo_product_id BIGINT UNSIGNED DEFAULT NULL,
			mapping_status VARCHAR(20) NOT NULL DEFAULT 'unmatched',
			confidence VARCHAR(20) DEFAULT NULL,
			source VARCHAR(50) DEFAULT NULL,
			mapped_at DATETIME DEFAULT NULL,
			saleson_name VARCHAR(255) DEFAULT NULL,
			woo_name VARCHAR(255) DEFAULT NULL,
			category VARCHAR(255) DEFAULT NULL,
			sku VARCHAR(100) DEFAULT NULL,
			stock INT DEFAULT NULL,
			notes VARCHAR(255) DEFAULT NULL,
			is_curated TINYINT(1) NOT NULL DEFAULT 0,
			listing_status VARCHAR(20) NOT NULL DEFAULT 'not_listed',
			first_seen_at DATETIME DEFAULT NULL,
			PRIMARY KEY (saleson_product_id),
			KEY woo_product_id (woo_product_id),
			KEY mapping_status (mapping_status),
			KEY is_curated (is_curated),
			KEY listing_status (listing_status)
		) {$charset_collate};";

		// Phase 2: mirrors saleson_product_map's shape for parties (SalesOn's term
		// for customers/dealers) - same reconciliation pattern (matched/unmatched/
		// excluded), same "review before creating anything" discipline that saved
		// us from a lot of duplicate-creation risk on the product side.
		$sql[] = "CREATE TABLE {$p}saleson_party_map (
			saleson_party_id BIGINT UNSIGNED NOT NULL,
			saleson_name VARCHAR(255) DEFAULT NULL,
			mobile VARCHAR(20) DEFAULT NULL,
			saleson_email VARCHAR(255) DEFAULT NULL,
			gstin VARCHAR(30) DEFAULT NULL,
			billing_address TEXT DEFAULT NULL,
			party_type VARCHAR(30) DEFAULT NULL,
			group_name VARCHAR(50) DEFAULT NULL,
			customer_type VARCHAR(50) DEFAULT NULL,
			credit_limit DECIMAL(12,2) DEFAULT NULL,
			credit_period INT DEFAULT NULL,
			amount_balance DECIMAL(12,2) DEFAULT NULL,
			woo_user_id BIGINT UNSIGNED DEFAULT NULL,
			woo_login_email VARCHAR(255) DEFAULT NULL,
			is_placeholder_email TINYINT(1) NOT NULL DEFAULT 0,
			generated_password VARCHAR(50) DEFAULT NULL,
			mapping_status VARCHAR(20) NOT NULL DEFAULT 'unmatched',
			exclude_reason VARCHAR(100) DEFAULT NULL,
			source VARCHAR(50) DEFAULT NULL,
			mapped_at DATETIME DEFAULT NULL,
			last_synced_at DATETIME DEFAULT NULL,
			PRIMARY KEY (saleson_party_id),
			KEY woo_user_id (woo_user_id),
			KEY mapping_status (mapping_status),
			KEY group_name (group_name)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$p}saleson_sync_logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_started_at DATETIME NOT NULL,
			run_finished_at DATETIME DEFAULT NULL,
			endpoint VARCHAR(100) NOT NULL,
			items_processed INT DEFAULT 0,
			error_count INT DEFAULT 0,
			error_message TEXT DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'running',
			PRIMARY KEY (id)
		) {$charset_collate};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}

		self::migrate_listing_status();
	}

	/**
	 * Backfills `listing_status` for rows that existed before the column did.
	 *
	 * Background: until 2026-08-20 the ONLY products that received stock/price
	 * updates were those flagged `is_curated = 1` (the original Phase 1
	 * 220-product list) - see the WHERE clauses that used to live in
	 * Saleson_Stock_Sync::push_to_matched_woo_products() and
	 * Saleson_Stock_Writeback::update_cache(). That made "staff add a product
	 * in SalesOn and it appears on the website" impossible: anything created
	 * outside that original list defaulted to is_curated = 0 and silently
	 * never synced again (confirmed live during Phase 3 testing - a website-
	 * created product was rejected by SalesOn for "Insufficient Stock"
	 * because its stock had never been pushed).
	 *
	 * The gate is now "is it linked to a website product" (mapping_status =
	 * matched AND woo_product_id IS NOT NULL), and `listing_status` separately
	 * controls storefront visibility. A delisted product keeps syncing, so its
	 * stock/price are already correct the moment staff relist it.
	 *
	 * dbDelta adds the column with its DEFAULT but does NOT apply that default
	 * to existing rows, so without this every already-live product would read
	 * as 'not_listed' and look delisted in the new Products console. Seeds from
	 * the real WordPress post_status - the actual source of truth for what is
	 * live right now - rather than from is_curated, which only says what was
	 * once intended.
	 *
	 * Safe to re-run: only touches rows still holding the untouched default.
	 */
	private static function migrate_listing_status() {
		global $wpdb;
		$map = $wpdb->prefix . 'saleson_product_map';

		// Anything currently published on the site is, by definition, listed.
		$wpdb->query(
			"UPDATE {$map} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 SET m.listing_status = 'listed'
			 WHERE m.listing_status = 'not_listed'
			   AND m.mapping_status = 'matched'
			   AND p.post_status = 'publish'"
		);

		// A linked product that is NOT published was deliberately taken down
		// (every unpublish path in this plugin sets draft, never delete), so
		// it is 'delisted' rather than 'not_listed' - the latter is reserved
		// for products that have never been on the storefront at all.
		$wpdb->query(
			"UPDATE {$map} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 SET m.listing_status = 'delisted'
			 WHERE m.listing_status = 'not_listed'
			   AND m.mapping_status = 'matched'
			   AND p.post_status != 'publish'"
		);

		// Backdate pre-existing rows. This MUST be a past date, not NOW():
		// Saleson_Product_Importer::auto_import_new() treats anything first
		// seen inside its recent window as a new arrival, so stamping the
		// existing catalog with NOW() would make all ~680 never-listed backlog
		// products (spare parts, "CANCEL..." records, duplicates) auto-create
		// website drafts on the very first cron run after this upgrade.
		// Their true first-seen date is unknown but definitely historical.
		$wpdb->query(
			"UPDATE {$map} SET first_seen_at = DATE_SUB( NOW(), INTERVAL 1 YEAR ) WHERE first_seen_at IS NULL"
		);
	}
}
