<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "Products" - the staff-facing product console (added 2026-08-20).
 *
 * Everything a non-technical MyCoolStar staff member needs for products, in
 * one screen: see every website product with its photo, live stock and price
 * from SalesOn, and turn each one on or off the storefront. Product data
 * itself (name, price, stock, category) is owned by SalesOn and is read-only
 * here - the only things this screen actually changes are (a) whether a
 * product is listed, and (b) linking a new SalesOn product to the website.
 * Photos and descriptions are edited in WooCommerce's own product editor,
 * which staff already have.
 *
 * Deliberately written without jargon: no "mapping_status", no "is_curated",
 * no SalesOn ids in the main view. The technical screens (SalesOn Matcher,
 * Pricing, Stock) still exist for engineering use.
 */
class Saleson_Products_Page {

	const PAGE_SLUG = 'saleson-products';
	const PER_PAGE  = 25;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_saleson_list_product', array( __CLASS__, 'handle_list_product' ) );
		add_action( 'admin_post_saleson_delist_product', array( __CLASS__, 'handle_delist_product' ) );
		add_action( 'admin_post_saleson_add_to_website', array( __CLASS__, 'handle_add_to_website' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Products', 'saleson-woo-sync' ),
			__( 'Products', 'saleson-woo-sync' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	// --- Actions --------------------------------------------------------------

	public static function handle_list_product() {
		self::guard( 'saleson_product_action' );

		$ids     = self::posted_ids();
		$changed = 0;
		foreach ( $ids as $woo_id ) {
			if ( self::set_listing( $woo_id, true ) ) {
				$changed++;
			}
		}

		self::notice( sprintf(
			/* translators: %d: number of products */
			_n( '%d product is now live on the website.', '%d products are now live on the website.', $changed, 'saleson-woo-sync' ),
			$changed
		), 'success' );

		self::redirect_back();
	}

	public static function handle_delist_product() {
		self::guard( 'saleson_product_action' );

		$ids     = self::posted_ids();
		$changed = 0;
		foreach ( $ids as $woo_id ) {
			if ( self::set_listing( $woo_id, false ) ) {
				$changed++;
			}
		}

		self::notice( sprintf(
			/* translators: %d: number of products */
			_n( '%d product was removed from the website.', '%d products were removed from the website.', $changed, 'saleson-woo-sync' ),
			$changed
		) . ' ' . __( 'Nothing was deleted - they can be put back any time, and their stock and price keep updating from SalesOn in the meantime.', 'saleson-woo-sync' ), 'success' );

		self::redirect_back();
	}

	public static function handle_add_to_website() {
		self::guard( 'saleson_product_action' );

		$saleson_ids = isset( $_REQUEST['saleson_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['saleson_ids'] ) ) : array();
		if ( isset( $_REQUEST['saleson_id'] ) ) {
			$saleson_ids[] = absint( wp_unslash( $_REQUEST['saleson_id'] ) );
		}

		$created = 0;
		$failed  = 0;
		foreach ( array_unique( array_filter( $saleson_ids ) ) as $saleson_id ) {
			$result = Saleson_Product_Importer::create_for( $saleson_id );
			if ( ! empty( $result['ok'] ) ) {
				$created++;
			} else {
				$failed++;
			}
		}

		$message = sprintf(
			/* translators: %d: number of products */
			_n( '%d product was added to the website as a draft.', '%d products were added to the website as drafts.', $created, 'saleson-woo-sync' ),
			$created
		) . ' ' . __( 'Add a photo and description, then use "Put on website" to make them live.', 'saleson-woo-sync' );

		if ( $failed ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of products */
				_n( '%d could not be added - check it has a name in SalesOn.', '%d could not be added - check they have names in SalesOn.', $failed, 'saleson-woo-sync' ),
				$failed
			);
		}

		self::notice( $message, $failed ? 'warning' : 'success' );
		self::redirect_back( array( 'tab' => 'new' ) );
	}

	/**
	 * Publishes or drafts a product and keeps listing_status in step.
	 * Never deletes - the same reversible discipline used everywhere else in
	 * this plugin, which has repeatedly made catalog mistakes recoverable.
	 */
	private static function set_listing( $woo_product_id, $listed ) {
		$product = wc_get_product( $woo_product_id );
		if ( ! $product ) {
			return false;
		}

		$product->set_status( $listed ? 'publish' : 'draft' );
		$product->save();

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'saleson_product_map',
			array( 'listing_status' => $listed ? 'listed' : 'delisted' ),
			array( 'woo_product_id' => $woo_product_id ),
			array( '%s' ),
			array( '%d' )
		);

		return true;
	}

	/**
	 * Reads from $_REQUEST rather than $_POST because bulk actions arrive as a
	 * POSTed form while single-row actions are nonce-protected GET links -
	 * admin-post.php dispatches both to the same handler.
	 */
	private static function posted_ids() {
		$ids = isset( $_REQUEST['product_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['product_ids'] ) ) : array();
		if ( isset( $_REQUEST['product_id'] ) ) {
			$ids[] = absint( wp_unslash( $_REQUEST['product_id'] ) );
		}
		return array_unique( array_filter( $ids ) );
	}

	private static function guard( $nonce_action ) {
		check_admin_referer( $nonce_action );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}
	}

	private static function notice( $message, $type = 'success' ) {
		set_transient( 'saleson_products_notice_' . get_current_user_id(), array( 'message' => $message, 'type' => $type ), 60 );
	}

	private static function redirect_back( $extra = array() ) {
		$args = array_merge(
			array( 'page' => self::PAGE_SLUG ),
			isset( $_REQUEST['return_tab'] ) ? array( 'tab' => sanitize_key( wp_unslash( $_REQUEST['return_tab'] ) ) ) : array(),
			$extra
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// --- Rendering ------------------------------------------------------------

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tab    = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'all';
		$tabs   = self::tabs();
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'all';
		}
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Products', 'saleson-woo-sync' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Product names, prices, stock and categories come from SalesOn automatically - change those in SalesOn, not here. Use this screen to add photos and descriptions, and to choose which products appear on the website.', 'saleson-woo-sync' ) . '</p>';

		self::render_notice();
		self::render_tabs( $tab, $search );
		self::render_search_box( $tab, $search );

		if ( 'new' === $tab ) {
			self::render_new_in_saleson();
		} else {
			self::render_product_table( $tab, $search );
		}

		echo '</div>';
	}

	private static function tabs() {
		return array(
			'all'        => __( 'All', 'saleson-woo-sync' ),
			'listed'     => __( 'On the website', 'saleson-woo-sync' ),
			'not_listed' => __( 'Not on the website', 'saleson-woo-sync' ),
			'no_photo'   => __( 'Needs a photo', 'saleson-woo-sync' ),
			'new'        => __( 'New in SalesOn', 'saleson-woo-sync' ),
		);
	}

	private static function render_notice() {
		$key    = 'saleson_products_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['message'] )
		);
	}

	private static function render_tabs( $current, $search ) {
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( self::tabs() as $slug => $label ) {
			$count = self::count_for_tab( $slug );
			$url   = add_query_arg(
				array_filter( array( 'page' => self::PAGE_SLUG, 'tab' => $slug, 's' => $search ) ),
				admin_url( 'admin.php' )
			);
			printf(
				'<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				esc_attr( $current === $slug ? 'nav-tab nav-tab-active' : 'nav-tab' ),
				esc_html( $label ),
				(int) $count
			);
		}
		echo '</h2>';
	}

	private static function render_search_box( $tab, $search ) {
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin: 1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
				placeholder="<?php esc_attr_e( 'Search products by name', 'saleson-woo-sync' ); ?>" class="regular-text" />
			<?php submit_button( __( 'Search', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?>
			<?php if ( '' !== $search ) : ?>
				<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) ) ); ?>" class="button-link" style="margin-left:8px;">
					<?php esc_html_e( 'Clear search', 'saleson-woo-sync' ); ?>
				</a>
			<?php endif; ?>
		</form>
		<?php
	}

	// --- Data -----------------------------------------------------------------

	/**
	 * Builds the WHERE fragment for each tab. Products are the wp_posts rows;
	 * the map is LEFT JOINed so a website product with no SalesOn link still
	 * appears (staff should be able to see it, not have it vanish silently).
	 */
	private static function tab_where( $tab, $search, &$params ) {
		global $wpdb;

		$where = "p.post_type = 'product' AND p.post_status IN ( 'publish', 'draft' )";

		if ( 'listed' === $tab ) {
			$where .= " AND p.post_status = 'publish'";
		} elseif ( 'not_listed' === $tab ) {
			$where .= " AND p.post_status = 'draft'";
		} elseif ( 'no_photo' === $tab ) {
			$where .= " AND NOT EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm
				WHERE pm.post_id = p.ID AND pm.meta_key = '_thumbnail_id' AND pm.meta_value != ''
			)";
		}

		if ( '' !== $search ) {
			$where   .= ' AND p.post_title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		return $where;
	}

	private static function count_for_tab( $tab ) {
		global $wpdb;

		if ( 'new' === $tab ) {
			return (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$wpdb->prefix}saleson_product_map
				 WHERE woo_product_id IS NULL AND mapping_status = 'unmatched'"
			);
		}

		$params = array();
		$where  = self::tab_where( $tab, '', $params );
		$sql    = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}";

		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) );
	}

	private static function get_paged() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	}

	// --- Tables ---------------------------------------------------------------

	private static function render_product_table( $tab, $search ) {
		global $wpdb;

		$paged  = self::get_paged();
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$params = array();
		$where  = self::tab_where( $tab, $search, $params );

		$count_sql = "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$rows_sql    = "SELECT p.ID, p.post_title, p.post_status, m.saleson_product_id
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->prefix}saleson_product_map m
				ON m.woo_product_id = p.ID AND m.mapping_status = 'matched'
			WHERE {$where}
			ORDER BY p.post_title ASC
			LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) );

		printf(
			'<p>%s</p>',
			esc_html( sprintf(
				/* translators: %d: number of products */
				_n( '%d product.', '%d products.', $total, 'saleson-woo-sync' ),
				$total
			) )
		);

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'Nothing here right now.', 'saleson-woo-sync' ) . '</em></p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'saleson_product_action' ); ?>
			<input type="hidden" name="return_tab" value="<?php echo esc_attr( $tab ); ?>" />

			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"><input type="checkbox" onclick="jQuery(this).closest('table').find('input[name=\'product_ids[]\']').prop('checked', this.checked);" /></td>
					<th style="width:60px;"><?php esc_html_e( 'Photo', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Product', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Price', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Status', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'saleson-woo-sync' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php self::render_product_row( $row, $tab ); ?>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<button type="submit" name="action" value="saleson_list_product" class="button button-primary">
					<?php esc_html_e( 'Put selected on website', 'saleson-woo-sync' ); ?>
				</button>
				<button type="submit" name="action" value="saleson_delist_product" class="button">
					<?php esc_html_e( 'Remove selected from website', 'saleson-woo-sync' ); ?>
				</button>
			</p>
		</form>
		<?php

		self::render_pagination( $total, $paged, array( 'tab' => $tab, 's' => $search ) );
	}

	private static function render_product_row( $row, $tab ) {
		$product = wc_get_product( $row->ID );
		if ( ! $product ) {
			return;
		}

		$is_live   = 'publish' === $row->post_status;
		$has_photo = has_post_thumbnail( $row->ID );
		$thumb     = $has_photo ? get_the_post_thumbnail( $row->ID, array( 50, 50 ) ) : '';
		$edit_url  = get_edit_post_link( $row->ID );
		$stock     = $product->get_stock_quantity();
		$price     = $product->get_regular_price();
		?>
		<tr>
			<th class="check-column"><input type="checkbox" name="product_ids[]" value="<?php echo esc_attr( $row->ID ); ?>" /></th>
			<td>
				<?php if ( $thumb ) : ?>
					<?php echo wp_kses_post( $thumb ); ?>
				<?php else : ?>
					<span style="display:inline-block;width:50px;height:50px;line-height:50px;text-align:center;background:#f0f0f1;color:#787c82;font-size:11px;border-radius:3px;">
						<?php esc_html_e( 'None', 'saleson-woo-sync' ); ?>
					</span>
				<?php endif; ?>
			</td>
			<td>
				<strong><?php echo esc_html( $row->post_title ); ?></strong>
				<?php
				$cats = wp_get_object_terms( $row->ID, 'product_cat', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $cats ) && $cats ) :
					?>
					<br /><span class="description"><?php echo esc_html( implode( ', ', $cats ) ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( null === $stock ) : ?>
					<span class="description"><?php esc_html_e( 'Not tracked', 'saleson-woo-sync' ); ?></span>
				<?php elseif ( $stock > 0 ) : ?>
					<?php echo esc_html( $stock ); ?>
				<?php else : ?>
					<span style="color:#b32d2e;"><?php esc_html_e( 'Out of stock', 'saleson-woo-sync' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php
				echo ( '' === $price || null === $price )
					? '<span class="description">' . esc_html__( 'No retail price', 'saleson-woo-sync' ) . '</span>'
					: esc_html( strip_tags( wc_price( $price ) ) );
				?>
			</td>
			<td>
				<?php if ( $is_live ) : ?>
					<span style="color:#008a20;font-weight:600;">&#9679; <?php esc_html_e( 'On website', 'saleson-woo-sync' ); ?></span>
				<?php else : ?>
					<span style="color:#787c82;">&#9675; <?php esc_html_e( 'Not on website', 'saleson-woo-sync' ); ?></span>
				<?php endif; ?>
				<?php if ( ! $has_photo ) : ?>
					<br /><span style="color:#996800;font-size:11px;"><?php esc_html_e( 'Needs a photo', 'saleson-woo-sync' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $edit_url ) : ?>
					<a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
						<?php esc_html_e( 'Edit photo & description', 'saleson-woo-sync' ); ?>
					</a>
				<?php endif; ?>
				<?php
				// Single-row actions are nonce-protected links rather than
				// submit buttons: the surrounding form is the BULK form, and a
				// row-level submit inside it would either fight the bulk
				// selection or need a shared hidden field mutated by JS.
				$action_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'     => $is_live ? 'saleson_delist_product' : 'saleson_list_product',
							'product_id' => $row->ID,
							'return_tab' => $tab,
						),
						admin_url( 'admin-post.php' )
					),
					'saleson_product_action'
				);
				$confirm = ( ! $is_live && ! $has_photo )
					? ' onclick="return confirm(' . esc_attr( wp_json_encode( __( 'This product has no photo yet. Put it on the website anyway?', 'saleson-woo-sync' ) ) ) . ');"'
					: '';
				printf(
					'<a href="%s" class="button button-small"%s>%s</a>',
					esc_url( $action_url ),
					$confirm, // phpcs:ignore WordPress.Security.EscapeOutput -- built from esc_attr'd JSON above
					esc_html( $is_live ? __( 'Remove from website', 'saleson-woo-sync' ) : __( 'Put on website', 'saleson-woo-sync' ) )
				);
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * SalesOn products with no website listing yet. The auto-import picks up
	 * genuinely new ones on its own; this tab exists so staff can also pull in
	 * anything from the historical backlog on demand.
	 */
	private static function render_new_in_saleson() {
		global $wpdb;

		$paged  = self::get_paged();
		$offset = ( $paged - 1 ) * self::PER_PAGE;
		$map    = $wpdb->prefix . 'saleson_product_map';
		$cache  = $wpdb->prefix . 'saleson_stock_cache';

		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$map} WHERE woo_product_id IS NULL AND mapping_status = 'unmatched'"
		);

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT m.saleson_product_id, m.saleson_name, m.category, m.first_seen_at,
			        c.name AS cache_name, c.stock, c.sell_price
			 FROM {$map} m
			 LEFT JOIN {$cache} c ON c.saleson_product_id = m.saleson_product_id
			 WHERE m.woo_product_id IS NULL AND m.mapping_status = 'unmatched'
			 ORDER BY m.first_seen_at DESC, m.saleson_product_id DESC
			 LIMIT %d OFFSET %d",
			self::PER_PAGE,
			$offset
		) );

		echo '<p class="description">' . esc_html__( 'These exist in SalesOn but have no page on the website yet. Genuinely new products are added automatically within a few minutes - use this list to pull in older ones you want to start selling online.', 'saleson-woo-sync' ) . '</p>';

		printf(
			'<p>%s</p>',
			esc_html( sprintf(
				/* translators: %d: number of products */
				_n( '%d product in SalesOn only.', '%d products in SalesOn only.', $total, 'saleson-woo-sync' ),
				$total
			) )
		);

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'Everything in SalesOn already has a page on the website.', 'saleson-woo-sync' ) . '</em></p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'saleson_product_action' ); ?>
			<input type="hidden" name="action" value="saleson_add_to_website" />
			<input type="hidden" name="return_tab" value="new" />

			<table class="widefat striped">
				<thead><tr>
					<td class="check-column"><input type="checkbox" onclick="jQuery(this).closest('table').find('input[name=\'saleson_ids[]\']').prop('checked', this.checked);" /></td>
					<th><?php esc_html_e( 'Product', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Category', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Price', 'saleson-woo-sync' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th class="check-column"><input type="checkbox" name="saleson_ids[]" value="<?php echo esc_attr( $row->saleson_product_id ); ?>" /></th>
						<td><strong><?php echo esc_html( $row->saleson_name ? $row->saleson_name : $row->cache_name ); ?></strong></td>
						<td><?php echo esc_html( $row->category ? $row->category : '—' ); ?></td>
						<td><?php echo esc_html( null !== $row->stock ? $row->stock : '—' ); ?></td>
						<td><?php echo esc_html( $row->sell_price ? strip_tags( wc_price( $row->sell_price ) ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<button type="submit" class="button button-primary">
					<?php esc_html_e( 'Add selected to the website', 'saleson-woo-sync' ); ?>
				</button>
				<span class="description" style="margin-left:8px;">
					<?php esc_html_e( 'They are added as drafts, so nothing goes live until you add a photo and put it on the website.', 'saleson-woo-sync' ); ?>
				</span>
			</p>
		</form>
		<?php

		self::render_pagination( $total, $paged, array( 'tab' => 'new' ) );
	}

	private static function render_pagination( $total, $paged, $extra = array() ) {
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages < 2 ) {
			return;
		}

		$base = add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG, 'paged' => '%#%' ), array_filter( $extra ) ),
			admin_url( 'admin.php' )
		);

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post( paginate_links( array(
			'base'    => $base,
			'format'  => '',
			'current' => $paged,
			'total'   => $total_pages,
		) ) );
		echo '</div></div>';
	}
}
