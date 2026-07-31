<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Pushes rate changes for a single product, in a single price-list tier, back
 * into SalesOn - and keeps the local wp_saleson_price_tiers cache in sync.
 *
 * SalesOn's price-list "update" endpoint is a full-record replace, not a
 * partial patch (confirmed Phase 0, phase0/findings.md "Two-way pricing sync
 * (website -> SalesOn) - confirmed GO"). Sending only the one changed
 * product would silently wipe every other product's rate out of that price
 * list. So every write here follows the same three-step pattern:
 *   1. GET the price list's current full record (name/description/type/status
 *      + full products[] array).
 *   2. Find-and-replace (or append) the one product's rate in that array,
 *      in memory - never touch anything else in the array.
 *   3. POST the WHOLE record back via patch_via_post(), carrying over the
 *      untouched name/description/type/status fields from the GET.
 *
 * This class also registers its own small admin screen directly (the
 * bootstrap does not call an init() for this class - it's used internally by
 * that screen and by Saleson_Product_Creator), so the admin_menu/admin_post
 * hooks are wired at the bottom of this file.
 */
class Saleson_Price_Writeback {

	const PAGE_SLUG = 'saleson-pricing';

	/**
	 * The 4 real SalesOn price lists (Phase 0 confirmed ids) plus the group
	 * they correspond to by NAME ONLY - SalesOn itself has no formal
	 * group -> price_list link (every group record's own price_list_id is
	 * null, confirmed Phase 0), so this mapping is this plugin's own
	 * convention, used only to label rows in the local cache/admin screen.
	 */
	public static function tiers() {
		return array(
			Saleson_API::PRICE_LIST_DISTRIBUTORS => array(
				'label'      => __( 'DISTRIBUTORS', 'saleson-woo-sync' ),
				'group_id'   => 8446,
				'group_name' => 'DISTRIBUTORS',
			),
			Saleson_API::PRICE_LIST_RETAIL => array(
				'label'      => __( 'RETAIL CUSTOMER', 'saleson-woo-sync' ),
				'group_id'   => 8448,
				'group_name' => 'CUSTOMERS',
			),
			Saleson_API::PRICE_LIST_DEALER => array(
				'label'      => __( 'DEALER', 'saleson-woo-sync' ),
				'group_id'   => 8455,
				'group_name' => 'DEALER',
			),
			Saleson_API::PRICE_LIST_SUPERMART => array(
				'label'      => __( 'SUPERMART', 'saleson-woo-sync' ),
				'group_id'   => 8445,
				'group_name' => 'SUPERMART',
			),
		);
	}

	// --- Core write-back logic ------------------------------------------------

	/**
	 * Update (or add, if missing) one product's rate inside an EXISTING price
	 * list. Never call this with a $price_list_id that might not exist yet -
	 * patch_via_post() on a nonexistent id crashes with a 500 (Phase 0).
	 *
	 * @return array{ok:bool,error?:mixed,data?:mixed}
	 */
	public static function update_product_rate_in_tier( $saleson_product_id, $price_list_id, $new_rate ) {
		$saleson_product_id = (int) $saleson_product_id;
		$price_list_id      = (int) $price_list_id;

		$api        = new Saleson_API();
		$get_result = $api->get( 'products/price-list/' . $price_list_id );

		if ( empty( $get_result['ok'] ) || empty( $get_result['data'] ) ) {
			$msg = 'update_product_rate_in_tier: failed to GET price-list ' . $price_list_id . ' before update - aborting write to avoid a blind full-record replace. '
				. self::stringify_error( $get_result );
			self::log_error( $msg );
			return array( 'ok' => false, 'error' => $msg );
		}

		$record   = self::extract_price_list_record( $get_result['data'] );
		$products = $record['products'];

		$found = false;
		foreach ( $products as $i => $p ) {
			$pid = self::product_id_of( $p );
			if ( $pid === $saleson_product_id ) {
				$products[ $i ]['product_id'] = $saleson_product_id;
				$products[ $i ]['rate']       = $new_rate;
				$found                        = true;
				break;
			}
		}
		if ( ! $found ) {
			$products[] = array(
				'product_id' => $saleson_product_id,
				'rate'       => $new_rate,
			);
		}

		$body = array(
			'name'        => $record['name'],
			'description' => $record['description'],
			'type'        => $record['type'],
			'status'      => $record['status'],
			'products'    => $products,
		);

		$patch_result = $api->patch_via_post( 'products/price-list/' . $price_list_id, $body );

		if ( empty( $patch_result['ok'] ) ) {
			$msg = 'update_product_rate_in_tier: PATCH failed for price-list ' . $price_list_id . ', product ' . $saleson_product_id . '. '
				. self::stringify_error( $patch_result );
			self::log_error( $msg );
			return array( 'ok' => false, 'error' => $msg );
		}

		self::update_local_cache( $saleson_product_id, $price_list_id, $new_rate );

		return array( 'ok' => true, 'data' => $patch_result['data'] );
	}

	/**
	 * Add a product that isn't in this price list yet. Same fetch-then-replace
	 * pattern as update_product_rate_in_tier() - which already appends the
	 * product when it isn't found - so this is a thin, explicitly-named alias
	 * for callers (like the product creator) where "add" reads more clearly
	 * than "update".
	 *
	 * @return array{ok:bool,error?:mixed,data?:mixed}
	 */
	public static function add_product_to_tier( $saleson_product_id, $price_list_id, $rate ) {
		return self::update_product_rate_in_tier( $saleson_product_id, $price_list_id, $rate );
	}

	/**
	 * Normalize the GET products/price-list/{id} response into
	 * {name, description, type, status, products[]}, regardless of which
	 * wrapper key SalesOn nests the record under.
	 */
	private static function extract_price_list_record( $data ) {
		if ( isset( $data['price_list'] ) && is_array( $data['price_list'] ) ) {
			$record = $data['price_list'];
		} elseif ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			$record = $data['data'];
		} else {
			$record = $data;
		}

		$record = wp_parse_args( $record, array(
			'name'        => '',
			'description' => '',
			'type'        => '',
			'status'      => 'ACTIVE',
			'products'    => array(),
		) );

		if ( ! is_array( $record['products'] ) ) {
			$record['products'] = array();
		}

		return $record;
	}

	private static function product_id_of( $p ) {
		if ( isset( $p['product_id'] ) ) {
			return (int) $p['product_id'];
		}
		if ( isset( $p['id'] ) ) {
			return (int) $p['id'];
		}
		return 0;
	}

	private static function update_local_cache( $saleson_product_id, $price_list_id, $rate ) {
		global $wpdb;
		$tiers = self::tiers();
		$meta  = isset( $tiers[ $price_list_id ] ) ? $tiers[ $price_list_id ] : array( 'group_id' => null, 'group_name' => null );

		$wpdb->replace(
			$wpdb->prefix . 'saleson_price_tiers',
			array(
				'saleson_product_id' => $saleson_product_id,
				'price_list_id'      => $price_list_id,
				'group_id'           => $meta['group_id'],
				'group_name'         => $meta['group_name'],
				'rate'               => $rate,
				'last_synced_at'     => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%d', '%s', '%f', '%s' )
		);
	}

	private static function stringify_error( $result ) {
		if ( ! is_array( $result ) ) {
			return '';
		}
		$error = isset( $result['error'] ) ? $result['error'] : '';
		return is_array( $error ) ? wp_json_encode( $error ) : (string) $error;
	}

	private static function log_error( $message ) {
		error_log( '[Saleson Price Writeback] ' . $message );
	}

	// --- Admin screen: "SalesOn Pricing" --------------------------------------

	public static function register_menu() {
		if ( class_exists( 'WooCommerce' ) ) {
			add_submenu_page(
				'woocommerce',
				__( 'SalesOn Pricing', 'saleson-woo-sync' ),
				__( 'SalesOn Pricing', 'saleson-woo-sync' ),
				'manage_woocommerce',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' )
			);
		} else {
			add_menu_page(
				__( 'SalesOn Pricing', 'saleson-woo-sync' ),
				__( 'SalesOn Pricing', 'saleson-woo-sync' ),
				'manage_woocommerce',
				self::PAGE_SLUG,
				array( __CLASS__, 'render_page' ),
				'dashicons-tag'
			);
		}
	}

	public static function handle_form_submit() {
		global $wpdb;
		check_admin_referer( 'saleson_update_price_tier' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$saleson_product_id = isset( $_POST['saleson_product_id'] ) ? absint( $_POST['saleson_product_id'] ) : 0;
		$price_list_id       = isset( $_POST['price_list_id'] ) ? absint( $_POST['price_list_id'] ) : 0;
		$rate                = isset( $_POST['rate'] ) ? (float) $_POST['rate'] : 0;

		$tiers = self::tiers();

		// Defense in depth against the search screen (or a hand-crafted URL) passing a
		// synthetic 'orphan'/'ignored' id - those aren't real SalesOn products and
		// SalesOn will 500 rather than cleanly error if we try to price one.
		$is_real_saleson_product = $saleson_product_id && $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}saleson_product_map WHERE saleson_product_id = %d AND mapping_status IN ('matched', 'unmatched')",
				$saleson_product_id
			)
		);

		if ( ! $is_real_saleson_product || ! isset( $tiers[ $price_list_id ] ) || $rate <= 0 ) {
			$notice = array(
				'type'    => 'error',
				'message' => __( 'Please provide a valid, real SalesOn product, tier, and a rate greater than zero.', 'saleson-woo-sync' ),
			);
		} else {
			$result = self::update_product_rate_in_tier( $saleson_product_id, $price_list_id, $rate );
			if ( ! empty( $result['ok'] ) ) {
				$notice = array(
					'type'    => 'success',
					'message' => sprintf(
						/* translators: 1: tier label, 2: rate */
						__( '%1$s rate updated to %2$s in SalesOn.', 'saleson-woo-sync' ),
						$tiers[ $price_list_id ]['label'],
						$rate
					),
				);
			} else {
				$notice = array(
					'type'    => 'error',
					'message' => sprintf(
						/* translators: %s: error detail */
						__( 'Failed to update SalesOn: %s', 'saleson-woo-sync' ),
						is_string( $result['error'] ) ? $result['error'] : wp_json_encode( $result['error'] )
					),
				);
			}
		}

		set_transient( 'saleson_pricing_notice_' . get_current_user_id(), $notice, 60 );

		$redirect = add_query_arg(
			array(
				'page'               => self::PAGE_SLUG,
				'saleson_product_id' => $saleson_product_id,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'saleson-woo-sync' ) );
		}

		$user_id = get_current_user_id();
		$notice  = get_transient( 'saleson_pricing_notice_' . $user_id );
		if ( $notice ) {
			delete_transient( 'saleson_pricing_notice_' . $user_id );
		}

		$search               = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$selected_product_id  = isset( $_GET['saleson_product_id'] ) ? absint( $_GET['saleson_product_id'] ) : 0;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SalesOn Pricing', 'saleson-woo-sync' ); ?></h1>

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

		// 'orphan' and 'ignored' rows carry synthetic ids (900xxx.../800xxx... offsets
		// from Saleson_Map_Importer, see includes/class-saleson-map-importer.php) for
		// Woo-only products that have no real SalesOn product at all - pricing makes no
		// sense for them and SalesOn will reject/500 if we try. Only 'matched' and
		// 'unmatched' rows carry a genuine SalesOn product_id.
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
			echo '<p>' . esc_html__( 'No matched products found for that search.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'SalesOn ID', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Woo Product', 'saleson-woo-sync' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$edit_url = add_query_arg(
				array(
					'page'               => self::PAGE_SLUG,
					'saleson_product_id' => $row->saleson_product_id,
				),
				admin_url( 'admin.php' )
			);
			echo '<tr>';
			echo '<td>' . esc_html( $row->saleson_product_id ) . '</td>';
			echo '<td>' . esc_html( $row->post_title ? $row->post_title : __( '(no matched Woo product)', 'saleson-woo-sync' ) ) . '</td>';
			echo '<td><a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit pricing', 'saleson-woo-sync' ) . '</a></td>';
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
			echo '<p>' . esc_html__( 'This is not a real SalesOn product (it has no SalesOn-side counterpart to price) - nothing to edit here.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		$current = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT price_list_id, rate, last_synced_at FROM {$wpdb->prefix}saleson_price_tiers WHERE saleson_product_id = %d",
				$saleson_product_id
			),
			OBJECT_K
		);

		echo '<h2>' . esc_html(
			sprintf(
				/* translators: %d: SalesOn product id */
				__( 'Edit pricing for SalesOn product #%d', 'saleson-woo-sync' ),
				$saleson_product_id
			)
		) . '</h2>';

		echo '<table class="widefat"><thead><tr>';
		echo '<th>' . esc_html__( 'Tier', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Current rate (cached)', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Last synced', 'saleson-woo-sync' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( self::tiers() as $price_list_id => $meta ) {
			$row = isset( $current[ $price_list_id ] ) ? $current[ $price_list_id ] : null;
			echo '<tr>';
			echo '<td>' . esc_html( $meta['label'] ) . '</td>';
			echo '<td>' . esc_html( $row ? $row->rate : '-' ) . '</td>';
			echo '<td>' . esc_html( $row ? $row->last_synced_at : __( 'never', 'saleson-woo-sync' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		?>

		<h3><?php esc_html_e( 'Update a rate', 'saleson-woo-sync' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'saleson_update_price_tier' ); ?>
			<input type="hidden" name="action" value="saleson_update_price_tier" />
			<input type="hidden" name="saleson_product_id" value="<?php echo esc_attr( $saleson_product_id ); ?>" />
			<table class="form-table">
				<tr>
					<th><label for="saleson_price_list_id"><?php esc_html_e( 'Tier', 'saleson-woo-sync' ); ?></label></th>
					<td>
						<select name="price_list_id" id="saleson_price_list_id">
							<?php foreach ( self::tiers() as $price_list_id => $meta ) : ?>
								<option value="<?php echo esc_attr( $price_list_id ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="saleson_rate"><?php esc_html_e( 'New rate', 'saleson-woo-sync' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" name="rate" id="saleson_rate" required /></td>
				</tr>
			</table>
			<?php submit_button( __( 'Update rate in SalesOn', 'saleson-woo-sync' ) ); ?>
		</form>
		<?php
	}
}

// This class has no bootstrap-called init() - it's used internally by the
// product creator too, so its admin screen registers itself here directly.
add_action( 'admin_menu', array( 'Saleson_Price_Writeback', 'register_menu' ) );
add_action( 'admin_post_saleson_update_price_tier', array( 'Saleson_Price_Writeback', 'handle_form_submit' ) );
