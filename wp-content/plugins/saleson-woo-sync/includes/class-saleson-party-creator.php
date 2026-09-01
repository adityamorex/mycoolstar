<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Creates a SalesOn party for a website customer who doesn't have one yet -
 * the last gap in Phase 3's order flow. Until this existed, orders from
 * anyone outside the ~2231 parties imported in Phase 2 were skipped with an
 * explanatory order note, which would have meant every new retail signup
 * silently never reaching SalesOn.
 *
 * API contract confirmed live 2026-08-20 by probing the endpoint's own
 * validation (no docs exist): POST parties requires only `type` and `name`;
 * everything else is optional. It returns { status, message, party: { id } }.
 *
 * Worth knowing: SalesOn does NOT validate `type` - it accepted a garbage
 * value and created the record. So the value here has to be right by our own
 * discipline, not because the API will catch a mistake. There is also no
 * DELETE for parties (405), so a bad create can only be renamed/deactivated,
 * never removed via the API - which is exactly why this class refuses to
 * create anything it isn't confident about rather than guessing.
 */
class Saleson_Party_Creator {

	/**
	 * SalesOn party-group ids, same values already used by
	 * Saleson_Price_Writeback::tiers(). A website signup with no dealer
	 * classification belongs in CUSTOMERS (the retail group).
	 */
	const GROUP_CUSTOMERS    = 8448;
	const GROUP_DEALER       = 8455;
	const GROUP_DISTRIBUTORS = 8446;
	const GROUP_SUPERMART    = 8445;

	private static function group_for_customer_type( $customer_type ) {
		switch ( strtolower( (string) $customer_type ) ) {
			case 'dealer':
				return self::GROUP_DEALER;
			case 'distributor':
				return self::GROUP_DISTRIBUTORS;
			case 'superstockist':
				return self::GROUP_SUPERMART;
			default:
				return self::GROUP_CUSTOMERS;
		}
	}

	/**
	 * Creates a SalesOn party from a WooCommerce order's customer details and
	 * records it in wp_saleson_party_map.
	 *
	 * Uses double-checked concurrency locking to ensure simultaneous orders from
	 * the same guest/customer do not create duplicate parties in SalesOn.
	 *
	 * @param WC_Order $order
	 * @return array{ok:bool, saleson_party_id?:int, error?:string}
	 */
	public static function create_from_order( $order ) {
		$user_id = $order->get_customer_id();

		$name = trim( $order->get_billing_company() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}
		if ( '' === $name ) {
			// Never create a nameless party - it can't be deleted afterwards
			// and would be unidentifiable to staff inside SalesOn.
			return array( 'ok' => false, 'error' => 'No customer name on the order.' );
		}

		$mobile = preg_replace( '/\D/', '', (string) $order->get_billing_phone() );
		$email  = trim( strtolower( (string) $order->get_billing_email() ) );

		// Step 1: Check if party already exists before attempting lock
		$existing_party_id = Saleson_Order_Submitter::resolve_party_id( $order );
		if ( $existing_party_id ) {
			return array( 'ok' => true, 'saleson_party_id' => (int) $existing_party_id );
		}

		// Step 2: Acquire a short-lived concurrency lock based on mobile or email
		$lock_suffix = $mobile ? substr( $mobile, -10 ) : ( $email ? md5( $email ) : 'order_' . $order->get_id() );
		$lock_key    = 'saleson_party_lock_' . $lock_suffix;
		$locked      = false;

		for ( $attempt = 0; $attempt < 6; $attempt++ ) {
			if ( false === get_transient( $lock_key ) ) {
				set_transient( $lock_key, 1, 30 ); // 30-second TTL
				$locked = true;
				break;
			}
			usleep( 500000 ); // wait 0.5s for in-flight create to finish
		}

		// Step 3: Double-check party map inside the lock (in case parallel request created it)
		$existing_party_id = Saleson_Order_Submitter::resolve_party_id( $order );
		if ( $existing_party_id ) {
			if ( $locked ) {
				delete_transient( $lock_key );
			}
			return array( 'ok' => true, 'saleson_party_id' => (int) $existing_party_id );
		}

		$customer_type = $user_id ? get_user_meta( $user_id, 'customer_type', true ) : '';

		$address = trim( implode( ', ', array_filter( array(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			$order->get_billing_city(),
			$order->get_billing_state(),
			$order->get_billing_postcode(),
		) ) ) );

		$body = array(
			'type'             => 'Customer',
			'name'             => $name,
			'group_id'         => self::group_for_customer_type( $customer_type ),
			'status'           => 'Active',
			'billing_address'  => $address,
			'shipping_address' => $address,
		);
		if ( $mobile ) {
			$body['mobile'] = $mobile;
		}
		if ( $email && is_email( $email ) && false === strpos( $email, '@placeholder.mycoolstar.com' ) ) {
			$body['email'] = $email;
		}

		$api    = new Saleson_API();
		$result = $api->post( 'parties', $body );

		if ( $locked ) {
			delete_transient( $lock_key );
		}

		if ( empty( $result['ok'] ) || empty( $result['data']['party']['id'] ) ) {
			$error = isset( $result['error'] ) ? $result['error'] : 'Unknown error';
			error_log( sprintf(
				'[Saleson Party Creator] Failed to create SalesOn party for Woo order #%d: %s',
				$order->get_id(),
				is_array( $error ) ? wp_json_encode( $error ) : (string) $error
			) );
			return array( 'ok' => false, 'error' => is_string( $error ) ? $error : wp_json_encode( $error ) );
		}

		$party_id = (int) $result['data']['party']['id'];

		self::record_in_map( $party_id, $name, $mobile, $email, $customer_type, $user_id, $address );

		return array( 'ok' => true, 'saleson_party_id' => $party_id );
	}

	/**
	 * Mirrors the new party into wp_saleson_party_map so the rest of the
	 * integration (balance sync, order submission, the Customers screen)
	 * treats it exactly like a party imported from SalesOn.
	 *
	 * mapping_status is 'created' - the same value Saleson_Party_Account_Creator
	 * uses for "this link was made by us", as distinct from 'matched'
	 * (pre-existing account we merely recognised).
	 */
	private static function record_in_map( $party_id, $name, $mobile, $email, $customer_type, $user_id, $address ) {
		global $wpdb;

		$wpdb->replace(
			$wpdb->prefix . 'saleson_party_map',
			array(
				'saleson_party_id'     => $party_id,
				'saleson_name'         => $name,
				'mobile'               => $mobile ? $mobile : null,
				'saleson_email'        => $email ? $email : null,
				'billing_address'      => $address ? $address : null,
				'party_type'           => 'Customer',
				'group_name'           => $customer_type ? strtoupper( $customer_type ) : 'CUSTOMERS',
				'customer_type'        => $customer_type ? $customer_type : 'Retail',
				'woo_user_id'          => $user_id ? $user_id : null,
				'woo_login_email'      => $email ? $email : null,
				'is_placeholder_email' => 0,
				'mapping_status'       => 'created',
				'source'               => 'website_order',
				'mapped_at'            => current_time( 'mysql' ),
				'last_synced_at'       => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}
}
