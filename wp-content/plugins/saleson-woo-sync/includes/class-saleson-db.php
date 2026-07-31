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
			PRIMARY KEY (saleson_product_id),
			KEY woo_product_id (woo_product_id),
			KEY mapping_status (mapping_status),
			KEY is_curated (is_curated)
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
	}
}
