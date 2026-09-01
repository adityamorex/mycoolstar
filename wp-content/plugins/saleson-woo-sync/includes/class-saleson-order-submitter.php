<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 3: submits every WooCommerce order to SalesOn as a real Sales Order,
 * the moment it's placed - no manual staff approval, no credit-limit gate
 * (client decision 2026-08-16: staff manage everything from inside SalesOn
 * exactly as they do today; the website's only job is to create the order
 * there and later mirror its status back, see Saleson_Order_Status_Sync).
 *
 * Party resolution tries, in order: existing link by WordPress user ID,
 * then billing phone, then billing email (resolve_party_id() below) - so a
 * returning customer who was matched by phone/email gets auto-linked for
 * next time instead of creating a second party. Only when none of those
 * match does this fall back to Saleson_Party_Creator::create_from_order(),
 * which is safe to call from here even under concurrent orders: it takes
 * a short transient lock keyed on the customer's phone/email and re-checks
 * for an existing party inside that lock before creating one (2026-09-01 -
 * closes the race that otherwise let two near-simultaneous orders from a
 * brand-new customer create two SalesOn parties).
 */
class Saleson_Order_Submitter {

	const META_TRANSACTION_ID = '_saleson_transaction_id';
	const META_TRANSACTION_NO = '_saleson_transaction_no';
	const META_SUBMITTED_AT   = '_saleson_submitted_at';

	public static function init() {
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'submit' ), 20, 1 );
	}

	public static function submit( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Idempotency guard - never submit the same order twice.
		// $order->get_meta(), NOT get_post_meta(): this site runs HPOS, where
		// order meta lives in wc_orders_meta, not wp_postmeta. The write
		// below used get_post_meta()/update_post_meta() until 2026-09-01,
		// which meant this guard was self-consistent with its own writes but
		// every OTHER reader of this meta ($order->get_meta() in the wp-admin
		// SalesOn meta box, the customer-facing order status section, the
		// Orders-list Invoice column) saw nothing - so a website order could
		// be genuinely submitted and synced yet still show "Not yet submitted
		// to SalesOn" everywhere a human actually looks. Found while tracing
		// why an invoiced order showed no invoice in the Orders list.
		if ( $order->get_meta( self::META_TRANSACTION_ID ) ) {
			return;
		}

		$party_id = self::resolve_party_id( $order );

		// No existing match by User ID, phone, or email - create a new
		// SalesOn party rather than leaving the order stuck, so every order
		// still reaches SalesOn. Locked in Saleson_Party_Creator to prevent
		// duplicate parties from concurrent orders.
		if ( ! $party_id ) {
			$created = Saleson_Party_Creator::create_from_order( $order );
			if ( empty( $created['ok'] ) ) {
				$order->add_order_note( sprintf(
					/* translators: %s: error detail */
					__( 'Not synced to SalesOn: could not create a customer record for this order (%s). Add the customer in SalesOn manually, then resubmit.', 'saleson-woo-sync' ),
					$created['error']
				) );
				return;
			}
			$party_id = $created['saleson_party_id'];
			$order->add_order_note( sprintf(
				/* translators: %d: SalesOn party id */
				__( 'Created a new customer record in SalesOn (party #%d) for this order.', 'saleson-woo-sync' ),
				$party_id
			) );
		}

		$products = self::build_line_items( $order );
		if ( false === $products ) {
			// build_line_items() already added an explanatory order note.
			return;
		}

		$fields = self::build_fields( $order, $party_id, $products );

		$api    = new Saleson_API();
		$result = $api->request_multipart( 'POST', 'transactions/sales-order', array(), $fields );

		// The CREATE response shape is { "message": "...", "id": 123 } - NOT
		// wrapped in an "invoice" key like the GET-by-id response is (confirmed
		// live 2026-08-16, the two shapes genuinely differ). Checking for
		// data.invoice.id here was an earlier bug that misread every successful
		// submission as a failure.
		if ( empty( $result['ok'] ) || empty( $result['data']['id'] ) ) {
			$error = isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'saleson-woo-sync' );
			error_log( sprintf(
				'[Saleson Order Submitter] Failed to submit Woo order #%d to SalesOn: %s',
				$order_id,
				is_array( $error ) ? wp_json_encode( $error ) : (string) $error
			) );
			$order->add_order_note( sprintf(
				/* translators: %s: error detail */
				__( 'Failed to submit this order to SalesOn: %s. Safe to resubmit once fixed.', 'saleson-woo-sync' ),
				is_string( $error ) ? $error : wp_json_encode( $error )
			) );
			return;
		}

		$transaction_id = (int) $result['data']['id'];

		// The create response doesn't include the human-readable transaction_no
		// (e.g. "HBIPL-17134") - one follow-up GET fetches it for the order note
		// and for staff visibility. Not fatal if this second call fails; the
		// transaction id alone is enough for the status-sync to keep working.
		$transaction_no = (string) $transaction_id;
		$detail         = $api->get( 'transactions/sales-order/' . $transaction_id );
		if ( ! empty( $detail['ok'] ) && ! empty( $detail['data']['invoice']['transaction_no'] ) ) {
			$transaction_no = $detail['data']['invoice']['transaction_no'];
		}

		$order->update_meta_data( self::META_TRANSACTION_ID, $transaction_id );
		$order->update_meta_data( self::META_TRANSACTION_NO, sanitize_text_field( $transaction_no ) );
		$order->update_meta_data( self::META_SUBMITTED_AT, current_time( 'mysql' ) );
		$order->save();

		$order->add_order_note( sprintf(
			/* translators: %s: SalesOn transaction number */
			__( 'Submitted to SalesOn as %s.', 'saleson-woo-sync' ),
			$transaction_no
		) );
	}

	/**
	 * Resolves the SalesOn party ID for an order using a multi-signal hierarchy:
	 * 1. Logged-in WordPress user ID (if matched in wp_saleson_party_map)
	 * 2. Normalized billing phone (matching last 10 digits against party map)
	 * 3. Billing email (matching saleson_email or woo_login_email, ignoring placeholders)
	 *
	 * If a match is found for a logged-in user who was not yet linked, automatically
	 * links their woo_user_id in wp_saleson_party_map to avoid future lookups.
	 *
	 * @param WC_Order $order
	 * @return int|null SalesOn party ID or null if not found
	 */
	public static function resolve_party_id( $order ) {
		global $wpdb;
		$table       = $wpdb->prefix . 'saleson_party_map';
		$customer_id = $order->get_customer_id();

		// Tier 1: Direct link by WordPress User ID
		if ( $customer_id ) {
			$party_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT saleson_party_id FROM {$table} WHERE woo_user_id = %d AND mapping_status != 'excluded' LIMIT 1",
				$customer_id
			) );
			if ( $party_id ) {
				return (int) $party_id;
			}
		}

		// Tier 2: Normalized Billing Phone (matches exact digits or last 10 digits for Indian mobiles)
		$raw_phone = $order->get_billing_phone();
		$digits    = preg_replace( '/\D/', '', (string) $raw_phone );
		if ( strlen( $digits ) >= 10 ) {
			$last10 = substr( $digits, -10 );
			$matched_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT saleson_party_id, woo_user_id FROM {$table} 
				 WHERE (mobile = %s OR mobile = %s OR mobile LIKE %s) 
				   AND mapping_status != 'excluded' 
				 ORDER BY (woo_user_id IS NOT NULL) DESC, saleson_party_id ASC 
				 LIMIT 1",
				$digits,
				$last10,
				'%' . $wpdb->esc_like( $last10 )
			) );

			if ( $matched_row ) {
				// Auto-link registered user if they weren't linked yet
				if ( $customer_id && empty( $matched_row->woo_user_id ) ) {
					$wpdb->update(
						$table,
						array( 'woo_user_id' => $customer_id, 'last_synced_at' => current_time( 'mysql' ) ),
						array( 'saleson_party_id' => $matched_row->saleson_party_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
				}
				return (int) $matched_row->saleson_party_id;
			}
		}

		// Tier 3: Billing Email (ignoring synthetic placeholder emails)
		$email = trim( strtolower( (string) $order->get_billing_email() ) );
		if ( $email && is_email( $email ) && false === strpos( $email, '@placeholder.mycoolstar.com' ) ) {
			$matched_row = $wpdb->get_row( $wpdb->prepare(
				"SELECT saleson_party_id, woo_user_id FROM {$table} 
				 WHERE (LOWER(saleson_email) = %s OR LOWER(woo_login_email) = %s) 
				   AND mapping_status != 'excluded' 
				 ORDER BY (woo_user_id IS NOT NULL) DESC, saleson_party_id ASC 
				 LIMIT 1",
				$email,
				$email
			) );

			if ( $matched_row ) {
				// Auto-link registered user if they weren't linked yet
				if ( $customer_id && empty( $matched_row->woo_user_id ) ) {
					$wpdb->update(
						$table,
						array( 'woo_user_id' => $customer_id, 'last_synced_at' => current_time( 'mysql' ) ),
						array( 'saleson_party_id' => $matched_row->saleson_party_id ),
						array( '%d', '%s' ),
						array( '%d' )
					);
				}
				return (int) $matched_row->saleson_party_id;
			}
		}

		return null;
	}

	/**
	 * @return array|false Flat product rows on success, false if any item
	 *                     couldn't be resolved (an explanatory order note has
	 *                     already been added in that case).
	 */
	private static function build_line_items( $order ) {
		global $wpdb;
		$map_table = $wpdb->prefix . 'saleson_product_map';
		$api       = new Saleson_API();
		$rows      = array();

		foreach ( $order->get_items() as $item ) {
			$woo_product_id = $item->get_product_id();

			$saleson_product_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT saleson_product_id FROM {$map_table} WHERE woo_product_id = %d AND mapping_status IN ( 'matched', 'unmatched' ) LIMIT 1",
				$woo_product_id
			) );

			if ( ! $saleson_product_id ) {
				$order->add_order_note( sprintf(
					/* translators: %s: product name */
					__( 'Not synced to SalesOn: "%s" has no matching SalesOn product. Fix the mapping in SalesOn Products, then resubmit.', 'saleson-woo-sync' ),
					$item->get_name()
				) );
				return false;
			}

			$detail       = $api->get( 'products/' . (int) $saleson_product_id );
			$product_data = ! empty( $detail['ok'] ) && ! empty( $detail['data']['product'] ) ? $detail['data']['product'] : array();

			$quantity = (int) $item->get_quantity();
			// The customer's actual charged price, NOT SalesOn's master price -
			// they already saw and agreed to the website's (tier-correct) price.
			// Known simplification: WooCommerce prices are tax-inclusive on this
			// site while SalesOn calculates its own GST from the `gst` field
			// below - a full tax reconciliation is a follow-up item, not solved
			// here (see phase0/website_discovery_findings.md, "Tax configuration").
			$unit_price = $quantity > 0 ? round( (float) $item->get_total() / $quantity, 2 ) : 0;

			$rows[] = array(
				'product_id'      => (int) $saleson_product_id,
				'name'            => $item->get_name(),
				'hsn'             => isset( $product_data['hsn'] ) ? $product_data['hsn'] : '',
				'unit'            => isset( $product_data['unit'] ) ? $product_data['unit'] : 'Pcs',
				'multiplier'      => 1,
				'gst'             => isset( $product_data['gst'] ) ? $product_data['gst'] : 0,
				'cess'            => isset( $product_data['cess'] ) ? $product_data['cess'] : 0,
				'mrp'             => isset( $product_data['mrp'] ) ? $product_data['mrp'] : 0,
				'sell_price'      => $unit_price,
				'sp_with_gst'     => $unit_price,
				'quantity'        => $quantity,
				'committed_qty'   => 0,
				'free_qty'        => 0,
				'replace'         => 0,
				'discount_type'   => '',
				'discount_value'  => 0,
				'discount_amount' => 0,
				'stock'           => isset( $product_data['stock'] ) ? $product_data['stock'] : 0,
			);
		}

		return $rows;
	}

	/**
	 * Flattens $products into the exact bracket-notation field names SalesOn's
	 * "Create Sales Order" form submits (products[0][product_id], etc.) -
	 * Saleson_API::request_multipart() sends whatever key string it's given
	 * literally as the form field name, so a literal bracket-notation string
	 * key is all that's needed - no change to that helper.
	 */
	private static function build_fields( $order, $party_id, $products ) {
		$fields = array(
			'type'                => 'Sales Order',
			'party[party_id]'     => $party_id,
			'party[name]'         => $order->get_formatted_billing_full_name(),
			'party[address]'      => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
			'party[mobile]'       => $order->get_billing_phone(),
			'party[gstin]'        => '',
			'created_at'          => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			'grandTotal'          => $order->get_total(),
			'receivedAmount'      => 0,
			'balanceAmount'       => $order->get_total(),
			'discount'            => $order->get_total_discount(),
			'comment'             => sprintf( 'Website order #%d', $order->get_id() ),
			'creditApplied'       => 0,
		);

		foreach ( $products as $i => $row ) {
			foreach ( $row as $key => $value ) {
				if ( 'discount_type' === $key ) {
					$fields["products[{$i}][discount][type]"] = $value;
				} elseif ( 'discount_value' === $key ) {
					$fields["products[{$i}][discount][value]"] = $value;
				} elseif ( 'discount_amount' === $key ) {
					$fields["products[{$i}][discount][amount]"] = $value;
				} else {
					$fields["products[{$i}][{$key}]"] = $value;
				}
			}
		}

		return $fields;
	}
}
