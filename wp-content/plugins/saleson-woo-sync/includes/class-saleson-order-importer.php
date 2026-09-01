<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Inbound Order Sync: imports sales orders created directly inside SalesOn ERP
 * (offline, phone, counter orders) into WooCommerce so that ALL orders
 * are visible, searchable, and trackable in wp-admin.
 *
 * Runs as part of the periodic sync cycle in Saleson_Stock_Sync::run().
 * Fully idempotent: never duplicates orders that originated from the website
 * or orders that have already been imported.
 */
class Saleson_Order_Importer {

	/**
	 * Default batch size for fetching recent SalesOn orders.
	 */
	const DEFAULT_LIMIT = 50;

	/**
	 * Pulls recent sales orders from SalesOn and imports any that don't yet
	 * exist in WooCommerce.
	 *
	 * @param int $limit
	 * @return int Number of newly imported orders
	 */
	/**
	 * Transient key used to prevent two sync_from_saleson() runs overlapping.
	 * This cron fires roughly every minute; fetching full detail for each new
	 * order is a separate sequential API call, so a batch of even a dozen new
	 * orders can easily take longer than a minute on a shared host. Without
	 * this lock, the next cron tick starts a second run before the first
	 * finishes, both see the same "not yet imported" orders, and both create
	 * a copy - the exact bug that produced 15x duplicates of the same SalesOn
	 * order in production before this lock was added (2026-09-01).
	 */
	const LOCK_KEY = 'saleson_order_import_lock';
	const LOCK_TTL = 300; // seconds - well above how long one run should ever take

	public static function sync_from_saleson( $limit = self::DEFAULT_LIMIT ) {
		if ( false !== get_transient( self::LOCK_KEY ) ) {
			// A previous run is still in progress - skip this tick entirely
			// rather than racing it. The next tick will try again.
			return 0;
		}
		set_transient( self::LOCK_KEY, 1, self::LOCK_TTL );

		$log_id    = Saleson_Logger::start( 'order_inbound_sync' );
		$processed = 0;
		$errors    = 0;

		try {
			// Step 0: Auto-clean any duplicate orders or empty test orders
			self::cleanup_duplicate_orders();

			$api    = new Saleson_API();
			$result = $api->get( 'transactions/sales-order', array( 'page_size' => (int) $limit ) );

			if ( empty( $result['ok'] ) || empty( $result['data']['invoices'] ) ) {
				Saleson_Logger::finish( $log_id, 0, empty( $result['ok'] ) ? 1 : 0, $result['error'] ?? null );
				delete_transient( self::LOCK_KEY );
				return 0;
			}

			global $wpdb;
			$invoices = $result['data']['invoices'];

			foreach ( $invoices as $inv_summary ) {
				$transaction_id = isset( $inv_summary['id'] ) ? (int) $inv_summary['id'] : 0;
				if ( ! $transaction_id ) {
					continue;
				}

				// Idempotency check: has this SalesOn order already been imported
				// or did it originate from WooCommerce checkout? (Checks both postmeta & HPOS tables)
				if ( self::order_exists_for_transaction( $transaction_id ) ) {
					continue; // Already tracked in WooCommerce
				}

				// Fetch full order detail with line items and addresses
				$detail = $api->get( 'transactions/sales-order/' . $transaction_id );
				if ( empty( $detail['ok'] ) || empty( $detail['data']['invoice'] ) ) {
					$errors++;
					continue;
				}

				$order_data = $detail['data']['invoice'];
				$order_id   = self::create_woo_order( $order_data, $api );

				if ( $order_id ) {
					$processed++;
				} else {
					$errors++;
				}
			}

			Saleson_Logger::finish( $log_id, $processed, $errors, null );
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $processed, 1, $e->getMessage() );
		} finally {
			delete_transient( self::LOCK_KEY );
		}

		return $processed;
	}

	/**
	 * Creates a WooCommerce order from SalesOn order data.
	 *
	 * @param array       $order_data SalesOn order detail payload
	 * @param Saleson_API $api
	 * @return int|false WooCommerce order ID or false on failure
	 */
	private static function create_woo_order( $order_data, $api ) {
		global $wpdb;
		$party_table = $wpdb->prefix . 'saleson_party_map';
		$prod_table  = $wpdb->prefix . 'saleson_product_map';

		$transaction_id = (int) $order_data['id'];
		$transaction_no = isset( $order_data['transaction_no'] ) ? (string) $order_data['transaction_no'] : (string) $transaction_id;
		$party_id       = isset( $order_data['party_id'] ) ? (int) $order_data['party_id'] : 0;

		// Resolve WordPress customer ID from party map
		$woo_user_id = null;
		if ( $party_id ) {
			$woo_user_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT woo_user_id FROM {$party_table} WHERE saleson_party_id = %d AND woo_user_id IS NOT NULL LIMIT 1",
				$party_id
			) );
		}

		// Instantiate WC Order
		$order = wc_create_order( array(
			'customer_id' => $woo_user_id ? (int) $woo_user_id : 0,
			'created_via' => 'saleson_erp',
		) );

		if ( is_wp_error( $order ) || ! $order ) {
			error_log( sprintf(
				'[Saleson Order Importer] Failed to create WC order for SalesOn transaction #%d: %s',
				$transaction_id,
				is_wp_error( $order ) ? $order->get_error_message() : 'Unknown error'
			) );
			return false;
		}

		// Populate customer and address info
		$party      = isset( $order_data['party'] ) && is_array( $order_data['party'] ) ? $order_data['party'] : array();
		$name       = ! empty( $party['name'] ) ? $party['name'] : ( ! empty( $order_data['billing_name'] ) ? $order_data['billing_name'] : 'SalesOn Customer' );
		$name_parts = explode( ' ', trim( $name ), 2 );
		$first_name = $name_parts[0];
		$last_name  = isset( $name_parts[1] ) ? $name_parts[1] : '';

		$phone   = ! empty( $party['mobile'] ) ? $party['mobile'] : ( ! empty( $order_data['mobile'] ) ? $order_data['mobile'] : '' );
		$email   = ! empty( $party['email'] ) ? $party['email'] : '';
		$address = ! empty( $party['address'] ) ? $party['address'] : ( ! empty( $party['billing_address'] ) ? $party['billing_address'] : '' );

		$order->set_billing_first_name( $first_name );
		$order->set_billing_last_name( $last_name );
		$order->set_billing_company( $name );
		$order->set_billing_phone( $phone );
		if ( $email && is_email( $email ) ) {
			$order->set_billing_email( $email );
		}
		if ( $address ) {
			$order->set_billing_address_1( $address );
			$order->set_shipping_address_1( $address );
		}
		$order->set_shipping_first_name( $first_name );
		$order->set_shipping_last_name( $last_name );
		$order->set_shipping_company( $name );

		// Add Line Items
		$items = isset( $order_data['transaction_products'] ) && is_array( $order_data['transaction_products'] )
			? $order_data['transaction_products']
			: array();

		foreach ( $items as $tp ) {
			$saleson_prod_id = isset( $tp['product_id'] ) ? (int) $tp['product_id'] : 0;
			$qty             = isset( $tp['quantity'] ) && (int) $tp['quantity'] > 0 ? (int) $tp['quantity'] : 1;
			$price           = isset( $tp['sell_price'] ) ? (float) $tp['sell_price'] : ( isset( $tp['rate'] ) ? (float) $tp['rate'] : 0 );
			$item_name       = ! empty( $tp['name'] ) ? $tp['name'] : ( 'SalesOn Item #' . $saleson_prod_id );

			$woo_prod_id = null;
			if ( $saleson_prod_id ) {
				$woo_prod_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT woo_product_id FROM {$prod_table} WHERE saleson_product_id = %d AND woo_product_id IS NOT NULL LIMIT 1",
					$saleson_prod_id
				) );
			}

			$product_obj = $woo_prod_id ? wc_get_product( $woo_prod_id ) : null;

			if ( $product_obj ) {
				$order->add_product( $product_obj, $qty, array(
					'subtotal' => $price * $qty,
					'total'    => $price * $qty,
				) );
			} else {
				// Fallback: unmapped product added as generic line item
				$item = new WC_Order_Item_Product();
				$item->set_name( $item_name );
				$item->set_quantity( $qty );
				$item->set_subtotal( $price * $qty );
				$item->set_total( $price * $qty );
				$order->add_item( $item );
			}
		}

		// Payment method & Totals
		$order->set_payment_method( 'saleson_offline' );
		$order->set_payment_method_title( __( 'SalesOn ERP / Direct', 'saleson-woo-sync' ) );

		if ( isset( $order_data['grandTotal'] ) ) {
			$order->set_total( (float) $order_data['grandTotal'] );
		} elseif ( isset( $order_data['amount'] ) ) {
			$order->set_total( (float) $order_data['amount'] );
		}

		if ( ! empty( $order_data['created_at'] ) ) {
			$order->set_date_created( $order_data['created_at'] );
		}

		// Map status from SalesOn
		$saleson_status = isset( $order_data['status'] ) ? $order_data['status'] : 'Pending';
		$target_status  = isset( Saleson_Order_Status_Sync::STATUS_MAP[ $saleson_status ] )
			? Saleson_Order_Status_Sync::STATUS_MAP[ $saleson_status ]
			: 'saleson-pending';
		$order->set_status( $target_status );

		// Stamp Meta
		$order->update_meta_data( Saleson_Order_Submitter::META_TRANSACTION_ID, $transaction_id );
		$order->update_meta_data( Saleson_Order_Submitter::META_TRANSACTION_NO, sanitize_text_field( $transaction_no ) );
		$order->update_meta_data( '_saleson_origin', 'saleson_direct' );
		$order->update_meta_data( '_saleson_imported_at', current_time( 'mysql' ) );

		$order->save();

		// Dual-write legacy postmeta to ensure maximum compatibility across WP queries
		update_post_meta( $order->get_id(), Saleson_Order_Submitter::META_TRANSACTION_ID, (string) $transaction_id );
		update_post_meta( $order->get_id(), Saleson_Order_Submitter::META_TRANSACTION_NO, sanitize_text_field( $transaction_no ) );

		$order->add_order_note( sprintf(
			/* translators: %s: SalesOn transaction number */
			__( 'Imported from SalesOn ERP (Transaction %s). Origin: SalesOn Direct.', 'saleson-woo-sync' ),
			$transaction_no
		) );

		// If this order is already invoiced in SalesOn, sync the invoice details now
		Saleson_Order_Status_Sync::maybe_sync_invoice( $order, $order_data, $api );

		return $order->get_id();
	}

	/**
	 * Checks if a WooCommerce order exists for a given SalesOn transaction ID.
	 * Checks both standard wp_postmeta and WooCommerce HPOS (High-Performance Order Storage) tables.
	 *
	 * @param int $transaction_id
	 * @return int Order ID if exists, 0 otherwise
	 */
	public static function order_exists_for_transaction( $transaction_id ) {
		global $wpdb;

		// 1. Check legacy postmeta
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND (meta_value = %s OR meta_value = %d) LIMIT 1",
			Saleson_Order_Submitter::META_TRANSACTION_ID,
			(string) $transaction_id,
			(int) $transaction_id
		) );
		if ( $exists ) {
			return (int) $exists;
		}

		// 2. Check HPOS table if it exists
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$hpos_exists = $wpdb->get_var( $wpdb->prepare(
				"SELECT order_id FROM {$hpos_table} WHERE meta_key = %s AND (meta_value = %s OR meta_value = %d) LIMIT 1",
				Saleson_Order_Submitter::META_TRANSACTION_ID,
				(string) $transaction_id,
				(int) $transaction_id
			) );
			if ( $hpos_exists ) {
				return (int) $hpos_exists;
			}
		}

		return 0;
	}

	/**
	 * Automatically purges duplicate order records and empty test orders in WooCommerce,
	 * ensuring exactly ONE genuine order is preserved per SalesOn transaction.
	 */
	public static function cleanup_duplicate_orders() {
		global $wpdb;

		// 1. Check legacy postmeta for duplicates
		$duplicates = $wpdb->get_results(
			"SELECT meta_value as tx_id, MIN(post_id) as keep_id, GROUP_CONCAT(post_id) as all_ids, COUNT(*) as cnt
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '" . Saleson_Order_Submitter::META_TRANSACTION_ID . "'
			 GROUP BY meta_value
			 HAVING cnt > 1"
		);

		if ( ! empty( $duplicates ) ) {
			foreach ( $duplicates as $dup ) {
				$all_ids = explode( ',', $dup->all_ids );
				foreach ( $all_ids as $oid ) {
					$oid = (int) $oid;
					if ( $oid !== (int) $dup->keep_id ) {
						$order = wc_get_order( $oid );
						// Trash, not force-delete: these are near-certainly
						// this importer's own duplicates, but a trashed order
						// is recoverable if that assumption is ever wrong -
						// force-delete on live business order data isn't.
						if ( $order ) {
							$order->delete();
						}
					}
				}
			}
		}

		// 2. Check HPOS table if present
		$hpos_table = $wpdb->prefix . 'wc_orders_meta';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			$hpos_dups = $wpdb->get_results(
				"SELECT meta_value as tx_id, MIN(order_id) as keep_id, GROUP_CONCAT(order_id) as all_ids, COUNT(*) as cnt
				 FROM {$hpos_table}
				 WHERE meta_key = '" . Saleson_Order_Submitter::META_TRANSACTION_ID . "'
				 GROUP BY meta_value
				 HAVING cnt > 1"
			);

			if ( ! empty( $hpos_dups ) ) {
				foreach ( $hpos_dups as $dup ) {
					$all_ids = explode( ',', $dup->all_ids );
					foreach ( $all_ids as $oid ) {
						$oid = (int) $oid;
						if ( $oid !== (int) $dup->keep_id ) {
							$order = wc_get_order( $oid );
							if ( $order ) {
								$order->delete( true );
							}
						}
					}
				}
			}
		}

		// 3. Trash empty zero-total orders left behind by a failed import of
		// this importer's own doing - scoped to created_via = 'saleson_erp'
		// only. Deliberately NOT matching every zero-total shop_order created
		// today: that would also catch a genuine customer order that happens
		// to be $0 (a comp order, or one still mid-checkout), which has
		// nothing to do with this importer and should never be touched here.
		$empty_orders = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '" . Saleson_Order_Submitter::META_TRANSACTION_ID . "'
			 WHERE p.post_type = 'shop_order' AND pm.meta_value IS NULL AND p.post_date >= CURDATE()"
		);
		if ( ! empty( $empty_orders ) ) {
			foreach ( $empty_orders as $e_id ) {
				$order = wc_get_order( $e_id );
				if ( $order && 'saleson_erp' === $order->get_created_via() && (float) $order->get_total() == 0 ) {
					$order->delete();
				}
			}
		}
	}
}
