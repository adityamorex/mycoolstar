<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 2, step 3: turns "unmatched" rows in wp_saleson_party_map into real
 * WordPress customer accounts. Deliberately batched (default 300/click)
 * rather than all-at-once like the product bulk-publish tool - at ~2368
 * accounts, a single synchronous request risks PHP's max_execution_time on
 * typical hosting. Every batch only touches rows still 'unmatched', so
 * clicking the button repeatedly is safe and naturally resumable - no
 * separate cursor/state needed.
 *
 * Login decision (2026-08-10): no OTP/SMS. Username = mobile number,
 * password = deterministic combination of name + mobile, stored in plaintext
 * on the party-map row so staff can look it up and share it on request. No
 * self-service reset for now.
 */
class Saleson_Party_Account_Creator {

	const DEFAULT_BATCH_SIZE = 300;

	/**
	 * @return array{created:int, skipped:int, remaining:int}
	 */
	public static function create_batch( $limit = self::DEFAULT_BATCH_SIZE, $group_filter = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_party_map';

		if ( $group_filter ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE mapping_status = 'unmatched' AND group_name = %s ORDER BY saleson_party_id ASC LIMIT %d",
				$group_filter, $limit
			) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE mapping_status = 'unmatched' ORDER BY saleson_party_id ASC LIMIT %d",
				$limit
			) );
		}

		// These are bulk-created placeholder accounts - most placeholder emails
		// wouldn't even receive it, and the ones with real emails shouldn't get
		// a cold "your account was created" email for an account they didn't
		// request themselves.
		remove_action( 'user_register', 'wp_send_new_user_notifications' );

		$created = 0;
		$skipped = 0;

		foreach ( $rows as $row ) {
			$ok = self::create_one( $row );
			if ( $ok ) {
				$created++;
			} else {
				$skipped++;
			}
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'unmatched'" );

		return array(
			'created'   => $created,
			'skipped'   => $skipped,
			'remaining' => $remaining,
		);
	}

	private static function create_one( $row ) {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_party_map';

		$username = self::normalize_mobile( $row->mobile );

		if ( ! $username || username_exists( $username ) || email_exists( $row->woo_login_email ) ) {
			$wpdb->update(
				$table,
				array(
					'mapping_status' => 'excluded',
					'exclude_reason' => ! $username ? 'no_usable_mobile' : 'username_collision',
				),
				array( 'saleson_party_id' => $row->saleson_party_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return false;
		}

		$password = self::generate_password( $row->saleson_name, $row->mobile, $row->saleson_party_id );

		$user_id = wp_insert_user( array(
			'user_login' => $username,
			'user_email' => $row->woo_login_email,
			'user_pass'  => $password,
			'role'       => 'customer',
		) );

		if ( is_wp_error( $user_id ) ) {
			error_log( sprintf(
				'[Saleson Party Account Creator] Failed to create account for party #%d (%s): %s',
				$row->saleson_party_id, $row->saleson_name, $user_id->get_error_message()
			) );
			$wpdb->update(
				$table,
				array(
					'mapping_status' => 'excluded',
					'exclude_reason' => 'wp_insert_user_failed',
				),
				array( 'saleson_party_id' => $row->saleson_party_id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			return false;
		}

		update_user_meta( $user_id, 'mobile_number', $row->mobile );
		if ( $row->customer_type ) {
			update_user_meta( $user_id, 'customer_type', $row->customer_type );
		}
		if ( $row->saleson_name ) {
			update_user_meta( $user_id, 'firm_name', trim( $row->saleson_name ) );
		}
		if ( $row->gstin ) {
			update_user_meta( $user_id, 'gst_number', $row->gstin );
		}

		// billing_address from SalesOn is a single free-text field (confirmed
		// live 2026-08-10), not broken into city/state - store as-is rather
		// than guess-parse it.
		$billing_address = isset( $row->billing_address ) ? $row->billing_address : null;
		if ( $billing_address ) {
			update_user_meta( $user_id, 'complete_address', $billing_address );
		}

		$wpdb->update(
			$table,
			array(
				'woo_user_id'        => $user_id,
				'generated_password' => $password,
				'mapping_status'     => 'created',
				'mapped_at'          => current_time( 'mysql' ),
			),
			array( 'saleson_party_id' => $row->saleson_party_id ),
			array( '%d', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return true;
	}

	private static function normalize_mobile( $mobile ) {
		$digits = preg_replace( '/\D/', '', (string) $mobile );
		if ( strlen( $digits ) < 6 ) {
			return '';
		}
		return $digits;
	}

	/**
	 * Deterministic password: first 4 letters of the name (capitalized) + last
	 * 4 digits of the mobile number. No randomness, by design - reproducible
	 * and debuggable, matches the "unique combination of mobile number and
	 * name" instruction. Not meant to be cryptographically strong; staff hold
	 * every password in the admin panel regardless.
	 */
	private static function generate_password( $name, $mobile, $party_id ) {
		$letters = preg_replace( '/[^A-Za-z]/', '', (string) $name );
		$letters = strtolower( substr( $letters, 0, 4 ) );
		if ( strlen( $letters ) < 4 ) {
			$letters = str_pad( $letters, 4, 'x' );
		}
		if ( '' === trim( $letters, 'x' ) ) {
			$letters = 'cust';
		}
		$name_part = ucfirst( $letters );

		$digits = preg_replace( '/\D/', '', (string) $mobile );
		$digit_part = substr( $digits, -4 );
		if ( strlen( $digit_part ) < 4 ) {
			$digit_part = str_pad( (string) $party_id, 4, '0', STR_PAD_LEFT );
			$digit_part = substr( $digit_part, -4 );
		}

		return $name_part . $digit_part;
	}
}
