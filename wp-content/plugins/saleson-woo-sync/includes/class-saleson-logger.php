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
}
