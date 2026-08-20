<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pushes a stock change for a single product, at the confirmed real warehouse
 * (14229, HB Akeda Dungar), back into SalesOn - and keeps the local
 * wp_saleson_stock_cache in sync.
 *
 * SalesOn's stock endpoint (`POST warehouses/products/{id}/adjust-stock`) is a
 * signed DELTA/adjustment, not a "set absolute value" call (confirmed live,
 * 2026-07-28: quantity:1 took a product from 215->216, quantity:-1 took it back
 * to 215). Staff on the website think in terms of "set stock to X", so this
 * class always re-reads the LIVE current stock immediately before writing
 * (never the local cache, which could be stale) and computes
 * delta = desired - live_current before calling the API. Using a stale delta
 * base would silently push the wrong absolute value.
 *
 * Registers its own small admin screen directly, same pattern as
 * Saleson_Price_Writeback - the bootstrap does not call an init() for this
 * class, the admin_menu/admin_post hooks are wired at the bottom of this file.
 */
class Saleson_Stock_Writeback {

	const PAGE_SLUG = 'saleson-stock';

	/**
	 * @return array{ok:bool, error?:string, old_stock?:int, new_stock?:int}
	 */
	public static function set_product_stock( $saleson_product_id, $desired_stock ) {
		$api = new Saleson_API();

		// Always re-read live, warehouse-scoped stock right before writing -
		// never trust the local cache for this, since a stale base would
		// silently compute the wrong delta and push an incorrect absolute value.
		// No working single-product filter exists on this endpoint (SalesOn's
		// own filters are unreliable - confirmed repeatedly in Phase 0), so this
		// pulls the full warehouse-scoped catalog and finds the one product in it.
		$full_result = $api->get( 'products', array( 'with_unit' => 'false', 'page_size' => 5000, 'warehouse_id' => Saleson_API::WAREHOUSE_ID ) );
		if ( ! $full_result['ok'] || empty( $full_result['data']['products'] ) ) {
			return array( 'ok' => false, 'error' => 'Could not fetch live stock from SalesOn.' );
		}

		$live_stock = null;
		foreach ( $full_result['data']['products'] as $p ) {
			if ( isset( $p['id'] ) && (int) $p['id'] === (int) $saleson_product_id ) {
				$live_stock = (int) round( (float) $p['stock'] );
				break;
			}
		}

		if ( null === $live_stock ) {
			return array( 'ok' => false, 'error' => 'Product not found in SalesOn (warehouse-scoped catalog).' );
		}

		$delta = (int) $desired_stock - $live_stock;

		if ( 0 === $delta ) {
			self::update_cache( $saleson_product_id, $live_stock );
			return array( 'ok' => true, 'old_stock' => $live_stock, 'new_stock' => $live_stock );
		}

		$user           = wp_get_current_user();
		$adjust_result = $api->post( "warehouses/products/{$saleson_product_id}/adjust-stock", array(
			'quantity'     => $delta,
			'unit'         => 'pcs',
			'comment'      => sprintf( 'Website stock update by %s via SalesOn Stock admin screen', $user ? $user->user_login : 'unknown user' ),
			'warehouse_id' => Saleson_API::WAREHOUSE_ID,
		) );

		if ( ! $adjust_result['ok'] ) {
			return array( 'ok' => false, 'error' => is_string( $adjust_result['error'] ) ? $adjust_result['error'] : wp_json_encode( $adjust_result['error'] ) );
		}

		// Re-read once more to confirm what actually landed, rather than trusting
		// that "Success" + our own arithmetic matches SalesOn's own result.
		$verify_result = $api->get( 'products', array( 'with_unit' => 'false', 'page_size' => 5000, 'warehouse_id' => Saleson_API::WAREHOUSE_ID ) );
		$actual_stock  = $live_stock + $delta;
		if ( $verify_result['ok'] && ! empty( $verify_result['data']['products'] ) ) {
			foreach ( $verify_result['data']['products'] as $p ) {
				if ( isset( $p['id'] ) && (int) $p['id'] === (int) $saleson_product_id ) {
					$actual_stock = (int) round( (float) $p['stock'] );
					break;
				}
			}
		}

		self::update_cache( $saleson_product_id, $actual_stock );

		return array( 'ok' => true, 'old_stock' => $live_stock, 'new_stock' => $actual_stock );
	}

	private static function update_cache( $saleson_product_id, $stock ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'saleson_stock_cache',
			array( 'stock' => $stock, 'last_synced_at' => current_time( 'mysql' ) ),
			array( 'saleson_product_id' => $saleson_product_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		// Mirror onto the linked WooCommerce product too, so the change is
		// visible on the storefront immediately rather than waiting for the
		// next cron tick. Gate widened 2026-08-20 from `is_curated = 1` to
		// simply "linked" - staff pushing stock from this screen expect it to
		// land on the website regardless of whether the product happened to be
		// in the original Phase 1 curated list.
		$map_table = $wpdb->prefix . 'saleson_product_map';
		$row       = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT woo_product_id FROM {$map_table} WHERE saleson_product_id = %d AND mapping_status = 'matched' AND woo_product_id IS NOT NULL",
				$saleson_product_id
			)
		);
		if ( $row && function_exists( 'wc_update_product_stock' ) ) {
			$product = wc_get_product( (int) $row->woo_product_id );
			if ( $product ) {
				wc_update_product_stock( $product, (int) $stock, 'set' );
			}
		}
	}

	// --- Admin screen: "SalesOn Stock" ----------------------------------------

	public static function register_menu() {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'SalesOn Stock', 'saleson-woo-sync' ),
				__( 'SalesOn Stock', 'saleson-woo-sync' ),
				'manage_woocommerce',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		} else {
			add_menu_page(
				__( 'SalesOn Stock', 'saleson-woo-sync' ),
				__( 'SalesOn Stock', 'saleson-woo-sync' ),
				'manage_woocommerce',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' ),
				'dashicons-archive'
			);
		}
	}

	public static function handle_form_submit() {
		global $wpdb;
		check_admin_referer( 'saleson_update_stock' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$saleson_product_id = isset( $_POST['saleson_product_id'] ) ? absint( $_POST['saleson_product_id'] ) : 0;
		$desired_stock       = isset( $_POST['desired_stock'] ) ? absint( $_POST['desired_stock'] ) : null;

		// Same defense in depth as the Pricing screen - synthetic orphan/ignored
		// ids aren't real SalesOn products, adjusting "stock" for one would 500.
		$is_real_saleson_product = $saleson_product_id && $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}saleson_product_map WHERE saleson_product_id = %d AND mapping_status IN ('matched', 'unmatched')",
				$saleson_product_id
			)
		);

		if ( ! $is_real_saleson_product || null === $desired_stock || $desired_stock < 0 ) {
			$notice = array(
				'type'    => 'error',
				'message' => __( 'Please provide a valid, real SalesOn product and a stock quantity of zero or more.', 'saleson-woo-sync' ),
			);
		} else {
			$result = self::set_product_stock( $saleson_product_id, $desired_stock );
			if ( ! empty( $result['ok'] ) ) {
				$notice = array(
					'type'    => 'success',
					'message' => sprintf(
						/* translators: 1: old stock, 2: new stock */
						__( 'Stock updated in SalesOn: %1$d -> %2$d.', 'saleson-woo-sync' ),
						$result['old_stock'],
						$result['new_stock']
					),
				);
			} else {
				$notice = array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: error detail */
						__( 'Failed to update stock in SalesOn: %s', 'saleson-woo-sync' ),
						$result['error']
					),
				);
			}
		}

		set_transient( 'saleson_stock_notice_' . get_current_user_id(), $notice, 60 );

		wp_safe_redirect( add_query_arg(
			array( 'page' => self::PAGE_SLUG, 'saleson_product_id' => $saleson_product_id ),
			admin_url( 'admin.php' )
		) );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'saleson-woo-sync' ) );
		}

		$user_id = get_current_user_id();
		$notice  = get_transient( 'saleson_stock_notice_' . $user_id );
		if ( $notice ) {
			delete_transient( 'saleson_stock_notice_' . $user_id );
		}

		$search              = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$selected_product_id = isset( $_GET['saleson_product_id'] ) ? absint( $_GET['saleson_product_id'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SalesOn Stock', 'saleson-woo-sync' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Sets stock at the confirmed real warehouse (HB Akeda Dungar, id 14229). Always reads the live current value first, so entering a number here sets the absolute stock, even though SalesOn itself only accepts a +/- adjustment.', 'saleson-woo-sync' ); ?></p>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
					<p><?php echo esc_html( $notice['message'] ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Find a product', 'saleson-woo-sync' ); ?></h2>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<input
					type="text"
					name="s"
					value="<?php echo esc_attr( $search ); ?>"
					placeholder="<?php esc_attr_e( 'SalesOn product id, or matched Woo product name', 'saleson-woo-sync' ); ?>"
					class="regular-text"
				/>
				<?php submit_button( __( 'Search', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?>
			</form>

			<?php
			if ( '' !== $search ) {
				self::render_search_results( $search );
			}
			if ( $selected_product_id ) {
				self::render_edit_form( $selected_product_id );
			}
			?>
		</div>
		<?php
	}

	private static function render_search_results( $search ) {
		global $wpdb;

		if ( ctype_digit( $search ) ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.saleson_product_id, m.woo_product_id, p.post_title
					 FROM {$wpdb->prefix}saleson_product_map m
					 LEFT JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
					 WHERE m.saleson_product_id = %d
					 AND m.mapping_status IN ('matched', 'unmatched')
					 LIMIT 20",
					(int) $search
				)
			);
		} else {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.saleson_product_id, m.woo_product_id, p.post_title
					 FROM {$wpdb->prefix}saleson_product_map m
					 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
					 WHERE p.post_title LIKE %s
					 AND m.mapping_status IN ('matched', 'unmatched')
					 LIMIT 20",
					$like
				)
			);
		}

		echo '<h2>' . esc_html__( 'Results', 'saleson-woo-sync' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No matching products found.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'SalesOn ID', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Woo Product', 'saleson-woo-sync' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$edit_url = add_query_arg(
				array( 'page' => self::PAGE_SLUG, 'saleson_product_id' => $row->saleson_product_id ),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td>' . esc_html( $row->saleson_product_id ) . '</td>';
			echo '<td>' . esc_html( $row->post_title ? $row->post_title : __( '(no matched Woo product)', 'saleson-woo-sync' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit stock', 'saleson-woo-sync' ) . '</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_edit_form( $saleson_product_id ) {
		global $wpdb;

		$is_real_saleson_product = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}saleson_product_map WHERE saleson_product_id = %d AND mapping_status IN ('matched', 'unmatched')",
				$saleson_product_id
			)
		);
		if ( ! $is_real_saleson_product ) {
			echo '<p>' . esc_html__( 'This is not a real SalesOn product - nothing to edit here.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		$cached = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stock, last_synced_at FROM {$wpdb->prefix}saleson_stock_cache WHERE saleson_product_id = %d",
				$saleson_product_id
			)
		);

		echo '<h2>' . esc_html( sprintf( __( 'Edit stock for SalesOn product #%d', 'saleson-woo-sync' ), $saleson_product_id ) ) . '</h2>';
		echo '<p>' . esc_html(
			$cached
				? sprintf( __( 'Last-known stock (cached, may be slightly stale): %d, as of %s. Submitting below always re-reads the live value first.', 'saleson-woo-sync' ), $cached->stock, $cached->last_synced_at )
				: __( 'No cached stock on file yet.', 'saleson-woo-sync' )
		) . '</p>';

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'saleson_update_stock' ); ?>
			<input type="hidden" name="action" value="saleson_update_stock" />
			<input type="hidden" name="saleson_product_id" value="<?php echo esc_attr( $saleson_product_id ); ?>" />
			<table class="form-table">
				<tr>
					<th><label for="saleson_desired_stock"><?php esc_html_e( 'Set stock to', 'saleson-woo-sync' ); ?></label></th>
					<td><input type="number" step="1" min="0" name="desired_stock" id="saleson_desired_stock" required /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Update stock in SalesOn', 'saleson-woo-sync' ) ); ?>
		</form>
		<?php
	}
}

add_action( 'admin_menu', array( 'Saleson_Stock_Writeback', 'register_menu' ) );
add_action( 'admin_post_saleson_update_stock', array( 'Saleson_Stock_Writeback', 'handle_form_submit' ) );
