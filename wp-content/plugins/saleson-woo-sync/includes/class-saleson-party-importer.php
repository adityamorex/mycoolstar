<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 2, step 1: pulls every SalesOn party (customer/dealer) and reconciles
 * against existing WordPress users into wp_saleson_party_map - same
 * map-first-before-touching-anything discipline that saved us repeatedly on
 * the product side this session. Never creates a WordPress user itself; that
 * is a deliberate separate, later, on-demand step (see Saleson_Party_Matcher_Page).
 *
 * Exclusions applied here (client-confirmed 2026-07-30):
 *  - party type "Supplier" - not a customer/dealer at all, excluded.
 *  - group "TEST" - SalesOn's own test data, excluded.
 *  - name starts with "CANCEL" - a deliberate cancelled/voided-record marker
 *    used in SalesOn (found 2026-08-05: 237 such records, all otherwise
 *    typed as real Customers - without this filter two of them produced
 *    false-positive matches to real WooCommerce accounts via a shared email).
 *  - name contains "DUMMY" - test/placeholder record (1 found: "Dummy client").
 *  - group "SUPERMART" maps to the website's "Superstockist" customer type
 *    (same tier, different name - confirmed with client, not a real mismatch).
 */
class Saleson_Party_Importer {

	const EXCLUDED_GROUP_TEST = 'TEST';

	/**
	 * SalesOn's own group_name -> the website's existing "customer_type" meta
	 * value (see class-saleson-matcher-page.php sibling admin screen for
	 * products; this is the parties equivalent). Groups not in this list
	 * (e.g. no group at all) are left blank for manual review.
	 */
	const GROUP_TO_CUSTOMER_TYPE = array(
		'DEALER'       => 'Dealer',
		'DISTRIBUTORS' => 'Distributor',
		'SUPERMART'    => 'Superstockist',
		'CUSTOMERS'    => 'Retail',
	);

	/**
	 * @return array{ok:bool, processed?:int, excluded?:int, matched?:int, unmatched?:int, error?:string}
	 */
	public static function run() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_party_map';
		$api   = new Saleson_API();

		$result = $api->get( 'parties', array( 'page_size' => 5000 ) );
		if ( ! $result['ok'] || empty( $result['data']['parties'] ) ) {
			return array( 'ok' => false, 'error' => 'Could not fetch parties from SalesOn.' );
		}

		$processed = 0;
		$excluded  = 0;
		$matched   = 0;
		$unmatched = 0;

		foreach ( $result['data']['parties'] as $party ) {
			$party_id  = (int) $party['id'];
			$type      = isset( $party['type'] ) ? $party['type'] : '';
			$group     = isset( $party['group_name'] ) ? $party['group_name'] : '';
			$mobile    = isset( $party['mobile'] ) ? trim( (string) $party['mobile'] ) : '';
			$email     = isset( $party['email'] ) ? trim( (string) $party['email'] ) : '';
			$name      = isset( $party['name'] ) ? trim( (string) $party['name'] ) : '';

			$name_upper = strtoupper( $name );

			$exclude_reason = null;
			if ( 'Supplier' === $type ) {
				$exclude_reason = 'supplier';
			} elseif ( self::EXCLUDED_GROUP_TEST === $group ) {
				$exclude_reason = 'test_group';
			} elseif ( 0 === strpos( $name_upper, 'CANCEL' ) ) {
				$exclude_reason = 'cancelled_record';
			} elseif ( false !== strpos( $name_upper, 'DUMMY' ) ) {
				$exclude_reason = 'dummy_record';
			}

			$customer_type = isset( self::GROUP_TO_CUSTOMER_TYPE[ $group ] ) ? self::GROUP_TO_CUSTOMER_TYPE[ $group ] : '';

			$woo_user_id       = null;
			$woo_login_email   = null;
			$is_placeholder    = 0;
			$mapping_status    = 'unmatched';

			if ( $exclude_reason ) {
				$mapping_status = 'excluded';
			} else {
				$existing = self::find_existing_user( $mobile, $email );
				if ( $existing ) {
					$mapping_status  = 'matched';
					$woo_user_id     = $existing->ID;
					$woo_login_email = $existing->user_email;
				} else {
					// Not created here - just records what the login email WOULD be
					// if/when this party is turned into a real account, so a reviewer
					// can see it up front rather than it being decided silently later.
					if ( $email && is_email( $email ) ) {
						$woo_login_email = $email;
					} else {
						$woo_login_email = 'party-' . $party_id . '@placeholder.mycoolstar.com';
						$is_placeholder  = 1;
					}
				}
			}

			$wpdb->replace(
				$table,
				array(
					'saleson_party_id'     => $party_id,
					'saleson_name'         => $name,
					'mobile'               => $mobile,
					'saleson_email'        => $email,
					'gstin'                => isset( $party['gstin'] ) ? $party['gstin'] : null,
					'billing_address'      => isset( $party['billing_address'] ) ? $party['billing_address'] : null,
					'party_type'           => $type,
					'group_name'           => $group,
					'customer_type'        => $customer_type,
					'credit_limit'         => isset( $party['credit_limit'] ) ? $party['credit_limit'] : null,
					'credit_period'        => isset( $party['credit_period'] ) ? $party['credit_period'] : null,
					'amount_balance'       => isset( $party['amount_balance'] ) ? $party['amount_balance'] : null,
					'woo_user_id'          => $woo_user_id,
					'woo_login_email'      => $woo_login_email,
					'is_placeholder_email' => $is_placeholder,
					'mapping_status'       => $mapping_status,
					'exclude_reason'       => $exclude_reason,
					'source'               => 'saleson_party_import',
					'last_synced_at'       => current_time( 'mysql' ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%d', '%f', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			$processed++;
			if ( 'excluded' === $mapping_status ) {
				$excluded++;
			} elseif ( 'matched' === $mapping_status ) {
				$matched++;
			} else {
				$unmatched++;
			}
		}

		return array(
			'ok'        => true,
			'processed' => $processed,
			'excluded'  => $excluded,
			'matched'   => $matched,
			'unmatched' => $unmatched,
		);
	}

	/**
	 * Tries to find a real, already-existing WordPress user for this party -
	 * by mobile_number meta first (the site's own custom field, see
	 * "Customer Classification" section on the user edit screen), then by
	 * exact email match. Returns null if neither signal finds anything -
	 * that is NOT the same as "should be excluded", just "not yet linked".
	 */
	private static function find_existing_user( $mobile, $email ) {
		if ( $mobile ) {
			$users = get_users( array(
				'meta_key'   => 'mobile_number',
				'meta_value' => $mobile,
				'number'     => 1,
				'fields'     => 'all',
			) );
			if ( ! empty( $users ) ) {
				return $users[0];
			}
		}
		if ( $email && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				return $user;
			}
		}
		return null;
	}
}
