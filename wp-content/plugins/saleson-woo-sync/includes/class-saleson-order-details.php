<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 4: shows SalesOn's order/invoice/payment status on the order screen,
 * for both staff (wp-admin) and the customer (their own order page) - plus
 * the one thing SalesOn has no structured field for at all: transport/Bilty-LR
 * details, which stays a plain manual field staff fill in themselves (see
 * phase0/phase3.5-product-console-plan.md, "Bilty/LR: NO STRUCTURED FIELD").
 *
 * Deliberately read-only on the SalesOn side: this class never writes
 * anything back to SalesOn, it only displays what Saleson_Order_Status_Sync
 * already pulled in. The one field it DOES let staff edit (Bilty/LR) is
 * pure WordPress order meta with no SalesOn counterpart.
 */
class Saleson_Order_Details {

	const META_BILTY = '_saleson_bilty_lr';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		// woocommerce_process_shop_order_meta, NOT save_post: this site runs
		// HPOS (confirmed live 2026-08-20 - Woo's newer order storage keeps
		// orders in their own table, not wp_posts), and save_post never fires
		// for an HPOS order screen since there's no WordPress post being
		// saved. This hook is WooCommerce's own HPOS-compatible equivalent,
		// firing on both classic and HPOS order edit screens alike.
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_bilty_field' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'render_customer_view' ) );

		// Invoice column on the wp-admin Orders list - so staff can see which
		// orders are invoiced without opening each one. HPOS is this site's
		// active storage (confirmed 2026-08-20), so those hooks are what
		// actually fire; the classic manage_* hooks are added too in case
		// HPOS compatibility mode ever changes, and cost nothing if unused.
		add_filter( 'woocommerce_shop_order_list_table_columns', array( __CLASS__, 'add_invoice_column' ) );
		add_action( 'woocommerce_shop_order_list_table_custom_column', array( __CLASS__, 'render_invoice_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_invoice_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_invoice_column_classic' ), 10, 2 );
	}

	// --- Orders list column (wp-admin Orders screen) --------------------------

	public static function add_invoice_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$new['saleson_invoice'] = __( 'Invoice', 'saleson-woo-sync' );
			}
		}
		if ( ! isset( $new['saleson_invoice'] ) ) {
			$new['saleson_invoice'] = __( 'Invoice', 'saleson-woo-sync' ); // order_status column not found - append instead
		}
		return $new;
	}

	public static function render_invoice_column( $column, $order ) {
		if ( 'saleson_invoice' !== $column ) {
			return;
		}
		self::render_invoice_cell( $order );
	}

	public static function render_invoice_column_classic( $column, $post_id ) {
		if ( 'saleson_invoice' !== $column ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order ) {
			self::render_invoice_cell( $order );
		}
	}

	private static function render_invoice_cell( $order ) {
		$invoice_no = $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_NO );
		if ( ! $invoice_no ) {
			echo '<span style="color:#999;">&#8212;</span>';
			return;
		}
		$url = $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_URL );
		echo esc_html( $invoice_no );
		if ( $url ) {
			echo ' <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View', 'saleson-woo-sync' ) . '</a>';
		}
	}

	// --- Staff view (wp-admin order edit screen) ------------------------------

	public static function add_meta_box() {
		$screen = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'saleson_order_details',
			__( 'SalesOn', 'saleson-woo-sync' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}

		$transaction_no = $order->get_meta( Saleson_Order_Submitter::META_TRANSACTION_NO );
		if ( ! $transaction_no ) {
			echo '<p>' . esc_html__( 'Not yet submitted to SalesOn.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		echo '<p><strong>' . esc_html__( 'SalesOn order', 'saleson-woo-sync' ) . ':</strong> ' . esc_html( $transaction_no ) . '</p>';

		self::render_invoice_block( $order );

		wp_nonce_field( 'saleson_save_bilty', 'saleson_bilty_nonce' );
		$bilty = $order->get_meta( self::META_BILTY );
		?>
		<p>
			<label for="saleson_bilty_lr"><strong><?php esc_html_e( 'Transport / Bilty-LR details', 'saleson-woo-sync' ); ?></strong></label>
			<br />
			<span class="description"><?php esc_html_e( 'SalesOn has no dedicated field for this - enter it here manually. Shown to the customer on their order page.', 'saleson-woo-sync' ); ?></span>
			<textarea name="saleson_bilty_lr" id="saleson_bilty_lr" rows="3" style="width:100%;margin-top:6px;"><?php echo esc_textarea( $bilty ); ?></textarea>
		</p>
		<?php
	}

	public static function save_bilty_field( $post_id ) {
		if ( ! isset( $_POST['saleson_bilty_nonce'] ) || ! wp_verify_nonce( $_POST['saleson_bilty_nonce'], 'saleson_save_bilty' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_shop_order', $post_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( ! $order ) {
			return;
		}
		if ( isset( $_POST['saleson_bilty_lr'] ) ) {
			$order->update_meta_data( self::META_BILTY, sanitize_textarea_field( wp_unslash( $_POST['saleson_bilty_lr'] ) ) );
			$order->save();
		}
	}

	// --- Customer view (their own order-details page) -------------------------

	public static function render_customer_view( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$transaction_no = $order->get_meta( Saleson_Order_Submitter::META_TRANSACTION_NO );
		if ( ! $transaction_no ) {
			return; // not synced yet - nothing SalesOn-specific to show
		}

		echo '<section class="saleson-order-details" style="margin-top:2em;">';
		echo '<h2>' . esc_html__( 'Order Status', 'saleson-woo-sync' ) . '</h2>';
		echo '<table class="woocommerce-table shop_table"><tbody>';

		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Reference', 'saleson-woo-sync' ),
			esc_html( $transaction_no )
		);

		self::render_invoice_row( $order );

		$bilty = $order->get_meta( self::META_BILTY );
		if ( $bilty ) {
			printf(
				'<tr><th>%s</th><td>%s</td></tr>',
				esc_html__( 'Transport / Tracking', 'saleson-woo-sync' ),
				nl2br( esc_html( $bilty ) ) // phpcs:ignore WordPress.Security.EscapeOutput -- nl2br output of already-escaped text
			);
		}

		echo '</tbody></table>';
		echo '</section>';
	}

	// --- Shared invoice rendering (staff sees a mini version, customer a table row) ---

	private static function render_invoice_block( $order ) {
		$invoice_no = $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_NO );
		if ( ! $invoice_no ) {
			echo '<p class="description">' . esc_html__( 'No invoice yet - SalesOn generates one when the order is confirmed.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		$amount = (float) $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_AMOUNT );
		$paid   = (float) $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_PAID );
		$due    = (float) $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_DUE );
		$url    = $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_URL );

		echo '<p><strong>' . esc_html__( 'Invoice', 'saleson-woo-sync' ) . ':</strong> ' . esc_html( $invoice_no ) . '<br />';
		echo esc_html( sprintf(
			/* translators: 1: amount, 2: paid, 3: due */
			__( 'Amount: %1$s · Paid: %2$s · Due: %3$s', 'saleson-woo-sync' ),
			wp_strip_all_tags( wc_price( $amount ) ),
			wp_strip_all_tags( wc_price( $paid ) ),
			wp_strip_all_tags( wc_price( $due ) )
		) );
		if ( $url ) {
			echo '<br /><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View invoice', 'saleson-woo-sync' ) . '</a>';
		}
		echo '</p>';
	}

	private static function render_invoice_row( $order ) {
		$invoice_no = $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_NO );
		if ( ! $invoice_no ) {
			return;
		}

		$amount = (float) $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_AMOUNT );
		$paid   = (float) $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_PAID );
		$due    = (float) $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_DUE );
		$url    = $order->get_meta( Saleson_Order_Status_Sync::META_INVOICE_URL );

		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Invoice', 'saleson-woo-sync' ),
			esc_html( $invoice_no )
		);
		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html__( 'Payment status', 'saleson-woo-sync' ),
			$due > 0
				? esc_html( sprintf(
					/* translators: 1: amount paid, 2: amount due */
					__( '%1$s paid, %2$s due', 'saleson-woo-sync' ),
					wp_strip_all_tags( wc_price( $paid ) ),
					wp_strip_all_tags( wc_price( $due ) )
				) )
				: esc_html__( 'Paid in full', 'saleson-woo-sync' )
		);
		if ( $url ) {
			printf(
				'<tr><th>%s</th><td><a href="%s" target="_blank" rel="noopener noreferrer">%s</a></td></tr>',
				esc_html__( 'Invoice document', 'saleson-woo-sync' ),
				esc_url( $url ),
				esc_html__( 'View / print invoice', 'saleson-woo-sync' )
			);
		}
	}
}
