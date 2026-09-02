<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every cron run and manual re-sync writes one row here - a failed run must be
 * visible on the settings page, never fail silently.
 */
class Saleson_Logger {

	public static function start( $endpoint ) {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'saleson_sync_logs', array(
			'run_started_at' => current_time( 'mysql' ),
			'endpoint'       => $endpoint,
			'status'         => 'running',
		) );
		return (int) $wpdb->insert_id;
	}

	public static function finish( $log_id, $items_processed, $error_count = 0, $error_message = null ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'saleson_sync_logs',
			array(
				'run_finished_at' => current_time( 'mysql' ),
				'items_processed' => $items_processed,
				'error_count'     => $error_count,
				'error_message'   => $error_message,
				'status'          => $error_count > 0 ? 'failed' : 'success',
			),
			array( 'id' => $log_id )
		);
	}

	public static function last_run() {
		global $wpdb;
		return $wpdb->get_row(
			"SELECT * FROM {$wpdb->prefix}saleson_sync_logs ORDER BY id DESC LIMIT 1"
		);
	}

	/**
	 * One row per distinct endpoint (its most recent run), not just the
	 * single most recent row overall - added 2026-09-02 alongside the
	 * split-endpoint cron redesign, so the Settings page can show every
	 * sync step's status side by side instead of whichever one happened to
	 * log last.
	 */
	public static function last_run_per_endpoint() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_sync_logs';
		return $wpdb->get_results(
			"SELECT l.* FROM {$table} l
			 INNER JOIN (
				 SELECT endpoint, MAX(id) AS max_id FROM {$table} GROUP BY endpoint
			 ) latest ON latest.max_id = l.id
			 ORDER BY l.endpoint ASC"
		);
	}
}
