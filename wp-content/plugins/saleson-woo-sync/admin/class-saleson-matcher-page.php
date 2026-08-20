<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Human-in-the-loop reconciliation UI for wp_saleson_product_map, seeded from the
 * Phase 0 spreadsheet via Saleson_Map_Importer.
 *
 * Four tabs, one per mapping_status:
 *  - matched   (183 seed rows) - Confirm (no-op) / Reject (back to unmatched, clears woo id)
 *  - unmatched (715 seed rows) - filterable by category, "Create as product" per row only
 *                                (Option B: on-demand, never automatic/bulk creation)
 *  - orphan    (54 seed rows)  - read-only reference list (Woo products with no SalesOn id)
 *  - ignored   (196 seed rows) - read-only reference list (blank placeholder Woo products)
 */
class Saleson_Matcher_Page {

	const PAGE_SLUG  = 'saleson-matcher';
	const PER_PAGE   = 25;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_saleson_run_map_import', array( __CLASS__, 'handle_run_import' ) );
		add_action( 'admin_post_saleson_reset_map', array( __CLASS__, 'handle_reset_map' ) );
		add_action( 'admin_post_saleson_run_curated_import', array( __CLASS__, 'handle_run_curated_import' ) );
		add_action( 'admin_post_saleson_unpublish_non_curated', array( __CLASS__, 'handle_unpublish_non_curated' ) );
		add_action( 'admin_post_saleson_unpublish_orphans', array( __CLASS__, 'handle_unpublish_orphans' ) );
		add_action( 'admin_post_saleson_unpublish_ignored', array( __CLASS__, 'handle_unpublish_ignored' ) );
		add_action( 'admin_post_saleson_matcher_confirm', array( __CLASS__, 'handle_confirm' ) );
		add_action( 'admin_post_saleson_matcher_reject', array( __CLASS__, 'handle_reject' ) );
		add_action( 'admin_post_saleson_matcher_create_product', array( __CLASS__, 'handle_create_product' ) );
		add_action( 'admin_post_saleson_matcher_bulk_create_products', array( __CLASS__, 'handle_bulk_create_products' ) );
		add_action( 'admin_post_saleson_finalize_publish_remaining', array( __CLASS__, 'handle_finalize_publish_remaining' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_curated_notices' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_new_product_reminder' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			Saleson_Admin_Menu::PARENT_SLUG,
			__( 'Matcher (advanced)', 'saleson-woo-sync' ),
			__( 'Matcher (advanced)', 'saleson-woo-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	// --- Action handlers --------------------------------------------------------

	public static function handle_run_import() {
		check_admin_referer( 'saleson_run_map_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$result = Saleson_Map_Importer::import_from_spreadsheet();

		set_transient( 'saleson_matcher_notice_' . get_current_user_id(), $result, 60 );

		self::redirect_back( array( 'tab' => 'matched' ) );
	}

	/**
	 * Discards every reviewer decision (Confirm/Reject/Create-as-product) by
	 * wiping wp_saleson_product_map entirely and re-importing the spreadsheet
	 * fresh, as if for the first time. Deliberately does NOT touch any
	 * WooCommerce products already created via "Create as product" - deleting
	 * real WordPress posts is a separate, explicit decision, never bundled
	 * silently into a "reset the mapping" action.
	 */
	public static function handle_reset_map() {
		check_admin_referer( 'saleson_reset_map' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}saleson_product_map" );

		$result = Saleson_Map_Importer::import_from_spreadsheet();

		// TRUNCATE wipes is_curated along with everything else - re-apply the
		// curated catalog immediately so this reset can't silently undo the
		// "only these 220 items" scoping decision.
		Saleson_Map_Importer::import_curated_list();

		set_transient( 'saleson_matcher_notice_' . get_current_user_id(), $result, 60 );

		self::redirect_back( array( 'tab' => 'matched' ) );
	}

	public static function handle_run_curated_import() {
		check_admin_referer( 'saleson_run_curated_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$result = Saleson_Map_Importer::import_curated_list();

		set_transient( 'saleson_matcher_curated_notice_' . get_current_user_id(), $result, 60 );

		self::redirect_back( array( 'tab' => 'unmatched' ) );
	}

	/**
	 * Unpublishes (sets to Draft - never deletes) every currently-'matched' Woo
	 * product whose SalesOn item is NOT in the curated list. Per instruction: only
	 * the curated 220 items should be live on the storefront; everything else's
	 * operations are ignored. Draft, not trash/delete, so this is fully reversible
	 * and a human can review before anything is permanently removed.
	 */
	public static function handle_unpublish_non_curated() {
		check_admin_referer( 'saleson_unpublish_non_curated' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		// Excludes any woo_product_id that is ALSO the live match for a curated
		// item elsewhere in the table - real bug found 2026-07-30: many SalesOn
		// "REPLACEMENT ..." / "CANCEL ..." / generic spare-part rows were matched
		// (non-curated) to the SAME Woo product a genuine curated item legitimately
		// points at (e.g. curated "9\" FRESH AIR FAN METAL" and a blank-named
		// non-curated row both resolved to Woo product 22752). Without this
		// exclusion, this tool would draft a real, currently-live curated product
		// out from under the curated match - it did, for 35 products, before this
		// fix. The second (UNION) branch closes the same hole one level deeper:
		// when a curated item's match is a WooCommerce VARIATION (e.g. a ceiling
		// fan color), a non-curated row can independently match that variation's
		// PARENT product directly - drafting the parent hides the curated
		// variation too, even though no single woo_product_id value is shared
		// between the two rows. Found live, 4 curated products affected via 2
		// draft parents (see phase1-key-findings-and-decisions.md).
		$rows  = $wpdb->get_results(
			"SELECT m.woo_product_id FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE m.mapping_status = 'matched' AND m.is_curated = 0 AND m.woo_product_id IS NOT NULL AND p.post_status = 'publish'
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )"
		);

		$count = 0;
		foreach ( $rows as $row ) {
			$woo_id = (int) $row->woo_product_id;
			if ( ! $woo_id || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}
			$product = wc_get_product( $woo_id );
			if ( ! $product ) {
				continue;
			}
			$product->set_status( 'draft' );
			$product->save();
			$count++;
		}

		set_transient( 'saleson_matcher_curated_notice_' . get_current_user_id(), array(
			'ok'      => true,
			'unpublished' => $count,
		), 60 );

		self::redirect_back( array( 'tab' => 'matched' ) );
	}

	public static function handle_unpublish_orphans() {
		check_admin_referer( 'saleson_unpublish_orphans' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		// Same collision exclusion as handle_unpublish_non_curated() - defense in
		// depth, since a genuine orphan row should by definition have no curated
		// match sharing its woo_product_id, but this makes that guarantee explicit
		// rather than assumed.
		$rows  = $wpdb->get_results(
			"SELECT m.woo_product_id FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE m.mapping_status = 'orphan' AND m.woo_product_id IS NOT NULL AND p.post_status = 'publish'
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )"
		);

		$count = 0;
		foreach ( $rows as $row ) {
			$woo_id = (int) $row->woo_product_id;
			if ( ! $woo_id || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}
			$product = wc_get_product( $woo_id );
			if ( ! $product ) {
				continue;
			}
			$product->set_status( 'draft' );
			$product->save();
			$count++;
		}

		set_transient( 'saleson_matcher_curated_notice_' . get_current_user_id(), array(
			'ok'          => true,
			'unpublished' => $count,
		), 60 );

		self::redirect_back( array( 'tab' => 'orphan' ) );
	}

	/**
	 * 'ignored' rows are Woo-only placeholder products (blank/no-content), same
	 * shape as orphans but never given an unpublish tool - they were assumed to
	 * be reference-only, but nothing actually enforces that they're not still
	 * live on the storefront. Same Draft-only, fully-reversible pattern.
	 */
	public static function handle_unpublish_ignored() {
		check_admin_referer( 'saleson_unpublish_ignored' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		// Same collision exclusion as handle_unpublish_non_curated() - defense in
		// depth, same reasoning as the orphan handler above.
		$rows  = $wpdb->get_results(
			"SELECT m.woo_product_id FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE m.mapping_status = 'ignored' AND m.woo_product_id IS NOT NULL AND p.post_status = 'publish'
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )"
		);

		$count = 0;
		foreach ( $rows as $row ) {
			$woo_id = (int) $row->woo_product_id;
			if ( ! $woo_id || ! function_exists( 'wc_get_product' ) ) {
				continue;
			}
			$product = wc_get_product( $woo_id );
			if ( ! $product ) {
				continue;
			}
			$product->set_status( 'draft' );
			$product->save();
			$count++;
		}

		set_transient( 'saleson_matcher_curated_notice_' . get_current_user_id(), array(
			'ok'          => true,
			'unpublished' => $count,
		), 60 );

		self::redirect_back( array( 'tab' => 'ignored' ) );
	}

	/**
	 * Answers "how are we doing on the 220" in one glance, since that's the only
	 * thing that actually matters here - everything else on this page (orphans,
	 * ignored placeholders, non-curated matches) is reference/cleanup noise.
	 */
	private static function render_curated_summary_box() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';

		$total_curated     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_curated = 1" );
		$matched_curated   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched'" );
		$unmatched_curated = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_curated = 1 AND mapping_status = 'unmatched'" );
		// Excludes rows whose woo_product_id is also a curated item's live match -
		// those aren't "extra" noise, the underlying product is legitimately live
		// because of the curated match (see the collision bug fixed 2026-07-30).
		$noise_count       = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE p.post_status = 'publish'
			 AND ( ( m.is_curated = 0 AND m.mapping_status = 'matched' ) OR ( m.mapping_status IN ( 'orphan', 'ignored' ) AND m.woo_product_id IS NOT NULL ) )
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )"
		);
		?>
		<div class="notice notice-info" style="padding: 10px 16px; margin: 1em 0;">
			<p style="margin: 0;"><strong><?php esc_html_e( 'Curated catalog status (the 220 products that matter):', 'saleson-woo-sync' ); ?></strong></p>
			<p style="margin: 6px 0 0;">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: total curated, 2: matched count, 3: still-need-creation count */
						__( '%1$d curated total - %2$d already matched to a website product, %3$d still need "Create as product" (Unmatched tab).', 'saleson-woo-sync' ),
						$total_curated,
						$matched_curated,
						$unmatched_curated
					)
				);
				?>
				<?php if ( $noise_count > 0 ) : ?>
					<br /><em>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: count of non-curated live products */
							__( '%d products outside the curated list are still live on the site (non-curated matches + orphans) - everything else on this page beyond that is read-only reference.', 'saleson-woo-sync' ),
							$noise_count
						)
					);
					?>
					</em>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private static function render_non_curated_matched_tool() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		// Joined against wp_posts and filtered to 'publish' - without this, a
		// product that was already unpublished still matches the mapping-table
		// condition alone and would keep showing up here forever, making the
		// "Unpublish all listed" button look like it did nothing.
		// Same collision exclusion as handle_unpublish_non_curated() - a row here
		// must never be one whose woo_product_id is also a curated item's live
		// match, or "Unpublish all listed" would draft a real curated product.
		$rows  = $wpdb->get_results(
			"SELECT m.saleson_product_id, m.saleson_name, m.woo_name, m.woo_product_id FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE m.mapping_status = 'matched' AND m.is_curated = 0 AND m.woo_product_id IS NOT NULL AND p.post_status = 'publish'
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )
			 ORDER BY m.saleson_name ASC"
		);

		if ( empty( $rows ) ) {
			return;
		}

		// Collapsed by default (native <details> disclosure arrow) - this list is
		// noise relative to the 220 curated products that are the actual focus,
		// so it shouldn't consume vertical space on every page load, but the
		// count in the summary line stays visible without expanding.
		echo '<details style="margin: 1em 0;">';
		echo '<summary style="cursor: pointer; font-weight: 600; font-size: 1.1em;">' . esc_html(
			sprintf(
				/* translators: %d: count of non-curated matched products */
				__( '%d products live but NOT in the curated list - click to review/unpublish', 'saleson-woo-sync' ),
				count( $rows )
			)
		) . '</summary>';

		echo '<p class="description">' . esc_html__( 'Currently live on the website but not part of the client-approved curated list. Unpublish (set to Draft, not deleted) if they should come down.', 'saleson-woo-sync' ) . '</p>';

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'SalesOn Name', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Woo Product', 'saleson-woo-sync' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->saleson_name ) . '</td>';
			echo '<td>' . esc_html( $row->woo_name ? $row->woo_name : $row->woo_product_id ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;"
			onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'This sets all %d listed products to Draft (not deleted - fully reversible). Continue?', 'saleson-woo-sync' ), count( $rows ) ) ); ?>');">
			<?php wp_nonce_field( 'saleson_unpublish_non_curated' ); ?>
			<input type="hidden" name="action" value="saleson_unpublish_non_curated" />
			<?php submit_button( __( 'Unpublish all listed (set to Draft)', 'saleson-woo-sync' ), 'delete', 'submit', false ); ?>
		</form>
		</details>
		<?php
	}

	/**
	 * @return int number of distinct Woo products currently claimed by more than
	 *             one curated, 'matched' SalesOn item - a real data problem (the
	 *             original name-based reconciliation had no way to know two
	 *             genuinely different SalesOn products, e.g. "Ring" vs "Hanger"
	 *             variants, shouldn't both point at one generic-named Woo listing).
	 *             Not fixable automatically - each group needs a human decision.
	 */
	private static function count_collision_groups() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM (
				SELECT woo_product_id FROM {$table}
				WHERE mapping_status = 'matched' AND is_curated = 1 AND woo_product_id IS NOT NULL
				GROUP BY woo_product_id HAVING COUNT(*) > 1
			) AS collision_groups"
		);
	}

	private static function render_collisions_tab() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';

		$woo_ids = $wpdb->get_col(
			"SELECT woo_product_id FROM {$table}
			 WHERE mapping_status = 'matched' AND is_curated = 1 AND woo_product_id IS NOT NULL
			 GROUP BY woo_product_id HAVING COUNT(*) > 1
			 ORDER BY woo_product_id ASC"
		);

		echo '<p class="description">' . esc_html__( 'Each group below is one WooCommerce product currently claimed by more than one curated SalesOn item - the original name-based matching had no way to tell these apart (e.g. "Ring" vs "Hanger" variants sharing a generic Woo product name). The SKU column is the strongest signal available: when it spells out one specific SalesOn name, that row is very likely the correct one (highlighted below) - keep that one, Reject the rest so they go back to Unmatched and can each get their own proper listing created.', 'saleson-woo-sync' ) . '</p>';

		if ( empty( $woo_ids ) ) {
			echo '<p>' . esc_html__( 'No collisions detected.', 'saleson-woo-sync' ) . '</p>';
			return;
		}

		foreach ( $woo_ids as $woo_id ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT saleson_product_id, saleson_name FROM {$table}
					 WHERE mapping_status = 'matched' AND is_curated = 1 AND woo_product_id = %d
					 ORDER BY saleson_name ASC",
					$woo_id
				)
			);

			$woo_post  = get_post( $woo_id );
			$woo_title = $woo_post ? $woo_post->post_title : sprintf( '#%d', $woo_id );
			$woo_sku   = '';
			if ( function_exists( 'wc_get_product' ) ) {
				$woo_product = wc_get_product( $woo_id );
				if ( $woo_product ) {
					$woo_sku = $woo_product->get_sku();
				}
			}

			echo '<div class="postbox" style="padding: 12px 16px; margin-bottom: 16px;">';
			echo '<h3>' . esc_html( sprintf( __( 'WooCommerce: %s (id %d)', 'saleson-woo-sync' ), $woo_title, $woo_id ) ) . '</h3>';
			echo '<p><strong>' . esc_html__( 'SKU:', 'saleson-woo-sync' ) . '</strong> ' . ( $woo_sku ? esc_html( $woo_sku ) : '<em>' . esc_html__( '(none set - no strong signal for this group, review manually)', 'saleson-woo-sync' ) . '</em>' ) . '</p>';
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'SalesOn ID', 'saleson-woo-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'SalesOn Name', 'saleson-woo-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'Action', 'saleson-woo-sync' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $rows as $row ) {
				$is_sku_match = $woo_sku && trim( strtolower( $woo_sku ) ) === trim( strtolower( $row->saleson_name ) );
				echo '<tr' . ( $is_sku_match ? ' style="background: #d7f7d7;"' : '' ) . '>';
				echo '<td>' . esc_html( $row->saleson_product_id ) . '</td>';
				echo '<td>' . esc_html( $row->saleson_name ) . ( $is_sku_match ? ' <strong>' . esc_html__( '(SKU match)', 'saleson-woo-sync' ) . '</strong>' : '' ) . '</td>';
				echo '<td>';
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'saleson_matcher_row' ); ?>
					<input type="hidden" name="action" value="saleson_matcher_reject" />
					<input type="hidden" name="saleson_product_id" value="<?php echo esc_attr( $row->saleson_product_id ); ?>" />
					<input type="hidden" name="return_tab" value="collisions" />
					<?php submit_button( __( 'Reject (keep Woo link elsewhere / create separately)', 'saleson-woo-sync' ), 'delete', 'submit', false ); ?>
				</form>
				<?php
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '</div>';
		}
	}

	public static function handle_confirm() {
		check_admin_referer( 'saleson_matcher_row' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}
		// Confirm is a no-op: the row is already 'matched'. Nothing to persist -
		// it exists purely so the reviewer has an explicit affirmative action.
		self::redirect_back( array( 'tab' => 'matched' ) );
	}

	public static function handle_reject() {
		check_admin_referer( 'saleson_matcher_row' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$saleson_id = isset( $_POST['saleson_product_id'] ) ? absint( $_POST['saleson_product_id'] ) : 0;
		if ( $saleson_id ) {
			global $wpdb;
			$wpdb->update(
				$wpdb->prefix . 'saleson_product_map',
				array(
					'mapping_status'  => 'unmatched',
					'woo_product_id' => null,
				),
				array( 'saleson_product_id' => $saleson_id )
			);
		}

		$return_tab = isset( $_POST['return_tab'] ) ? sanitize_key( $_POST['return_tab'] ) : 'matched';
		self::redirect_back( array( 'tab' => $return_tab ) );
	}

	public static function handle_create_product() {
		check_admin_referer( 'saleson_matcher_row' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$saleson_id = isset( $_POST['saleson_product_id'] ) ? absint( $_POST['saleson_product_id'] ) : 0;
		$new_product_id = null;

		if ( $saleson_id && function_exists( 'wc_get_product' ) ) {
			$new_product_id = self::create_product_for( $saleson_id );
		}

		// Send the reviewer straight to the new product's own edit screen - this is
		// the one moment a human is already looking at it, so it's the cheapest point
		// to add a photo/description. Falling back to the matcher list only if
		// creation failed for some reason.
		if ( $new_product_id ) {
			wp_safe_redirect( admin_url( 'post.php?post=' . absint( $new_product_id ) . '&action=edit&saleson_new_product=1' ) );
			exit;
		}

		self::redirect_back( array( 'tab' => 'unmatched' ) );
	}

	/**
	 * Bulk version of handle_create_product() - creates a draft product for
	 * EVERY currently curated+unmatched row in one pass, rather than one at a
	 * time. Safe to run in bulk specifically because create_product_for()
	 * already checks for an existing linked WooCommerce product before
	 * creating anything (see the duplicate-creation guard below) - so this
	 * can't accidentally create a second copy of something that already has a
	 * home. Intended for the case where every remaining row genuinely has no
	 * WooCommerce presence at all and the only real bottleneck left is adding
	 * photos, not reviewing each row individually.
	 */
	public static function handle_bulk_create_products() {
		check_admin_referer( 'saleson_bulk_create_products' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		$ids   = $wpdb->get_col( "SELECT saleson_product_id FROM {$table} WHERE mapping_status = 'unmatched' AND is_curated = 1" );

		$created = array();
		$failed  = 0;
		foreach ( $ids as $saleson_id ) {
			$new_id = self::create_product_for( (int) $saleson_id );
			if ( $new_id ) {
				$created[] = $new_id;
			} else {
				$failed++;
			}
		}

		set_transient( 'saleson_bulk_create_notice_' . get_current_user_id(), array(
			'created' => count( $created ),
			'failed'  => $failed,
			'ids'     => $created,
		), 60 );

		self::redirect_back( array( 'tab' => 'unmatched' ) );
	}

	/**
	 * Client decision 2026-08-05: publish all 220 curated products now rather
	 * than waiting for photos/descriptions - those get added later. This
	 * covers the 104 that were still Draft: 62 relinked to pre-existing blank
	 * placeholder posts (still carrying a generic "Product" title and a stale
	 * "ELECTRIC GEYSER" category left over from years ago) and 42 created by
	 * the bulk-create tool (already had a real name, but ~18 of them inherited
	 * a wrong category from stale spreadsheet data). Fixes name + category
	 * where confidently known, then publishes. Two items (SILENCER 18 INCH,
	 * Accessories) have no confident category match and are left as-is on
	 * that front - still published, just not category-corrected.
	 *
	 * @return int[] array of woo_product_ids actually published, for the notice.
	 */
	private static function finalize_and_publish_ids() {
		$names = array(
			24341 => 'VENTO 10" 250MM',
			24342 => 'TOWER BLADE 16 inch RIGHT CW PKG20',
			24343 => 'TOWER BLADE 16 inch LEFT ACW PKG20',
			24344 => 'SUN HEATER 12 INCH',
			24345 => 'SILENCER 18 INCH',
			24346 => 'PVC FAN HEATER MOVING',
			24347 => 'PREMIUM MINI MOTOR',
			24348 => 'PREMIUM BULLET MOTOR HANGER',
			24349 => 'POWER SURE (KOHINOOR) AL CEILING FAN 48" WHITE/IVORY',
			24350 => 'POWER SURE (KOHINOOR) AL CEILING FAN 48" MAT BLACK',
			24351 => 'POWER SURE (KOHINOOR) AL CEILING FAN 48" BROWN',
			24352 => 'MINI MOTOR CRC',
			24353 => 'MCS PUMP 12W',
			24354 => 'MCS JUMBO SWING COOLER',
			24355 => 'MCS INDUCTION INFRARED 2000W',
			24356 => 'MCS CHAMPION PRO COOLER',
			24357 => 'MCS 22" THAR COMMERCIAL COOLER',
			24358 => 'MCS 12 INCH DHRUV TOWER',
			24359 => 'MAC3 HEATER MOVING',
			24360 => 'Kauva Blade ACW LEFT',
			24361 => 'KAUVA BLADE CW RIGHT',
			24362 => 'JUICOMATIC JMG',
			24363 => 'IRIS PLUS MIXER GRINDER',
			24364 => 'GEYSER 5 LTR ABS BODY',
			24365 => 'GEC COPPER MOTOR 18 INCH WITH FRAME',
			24366 => 'FRESH AIR 9 INCH  PVC',
			24367 => 'EXCEL 8 INCH ROUND',
			24368 => 'EXCEL 6 INCH ROUND',
			24369 => 'EXCEL 4 INCH ROUND',
			24370 => 'D10 QUARTZ HEATER 2 ROD',
			24371 => 'CO1004 - 2/3 SPEED',
			24372 => 'Bullet Blade  3 Par PKG 60 PCS',
			24373 => 'BULLET FAN 3 BLADE PVC',
			24374 => 'BT MODEL PVC BLOWER HEATER',
			24375 => 'BLDC WALL FAN 16 INCH 5 LEAF',
			24376 => 'BLDC WALL FAN 16 INCH 3 LEAF',
			24377 => 'BLADE YOYO',
			24378 => 'BLADE WALL FAN  16 INCH',
			24379 => 'Accessories',
			24380 => 'AP BLADE 12 INCH pkg 45 pcs',
			24381 => 'AL18125 AL EXHAUST MOTOR 18 INCH 1.25 INCH PKG 6 PC',
			24382 => 'AL1001 MOTOR 8 SUT 1 SEASON PACKING 12 PCS',
			24383 => 'AL0751 MOTOR 6 SUT 1 SEASON PACKING 12 PCS',
			24384 => '9 inch Blade 5 Par',
			24385 => '4G MOTOR REGULAR',
			24386 => '4G 18 INCH LEFT Exhaust Blade anti clock wise',
			24387 => '4G 16 INCH RIGHT Exhaust Blade clock wise',
			24388 => '48" COPPER o2 IVORY CEILING FAN',
			24389 => '48" AL O2 MODEL SMOKE BROWN CEILING FAN',
			24390 => '48" AL O2 MODEL BROWN CEILING FAN',
			24391 => '48" AL O2 IVORY CEILING FAN',
			24393 => '22" Padestal Fan 4 FEET 4G BLADE (REGULAR)',
			24394 => '20" PADESTAL FAN 3 PAR 4 FEET',
			24395 => '20 INCH 5 PAR PUNJABI FARATA',
			24396 => '18 inch blade 5 par LIGHT PKG50',
			24397 => '18 inch blade 5 par HEAVY PKG24',
			24398 => '16 inch blade 5 par HEAVY',
			24399 => '15 INCH EXHAUST FRAME SET',
			24400 => '12 inch Blade 5 par PKG 50',
			24401 => '1" Bullet Motor RING PACKING 20 PCS',
			24402 => '1" BULLET MOTOR HANGER PACKING 20 PCS',
			24482 => 'MCS MINI CHILLER COOLER',
		);

		// term_id per real WooCommerce category (fetched live 2026-08-05 - see
		// phase0/phase1-key-findings-and-decisions.md for the category audit).
		$categories = array(
			24344 => 196, 24346 => 196, 24347 => 195, 24348 => 197, 24349 => 198,
			24350 => 198, 24351 => 198, 24352 => 195, 24353 => 194, 24354 => 201,
			24355 => 203, 24356 => 201, 24357 => 201, 24359 => 196, 24362 => 205,
			24363 => 205, 24364 => 131, 24365 => 195, 24366 => 199, 24370 => 196,
			24372 => 197, 24373 => 197, 24374 => 196, 24375 => 200, 24376 => 200,
			24378 => 200, 24380 => 219, 24381 => 459, 24382 => 195, 24383 => 195,
			24385 => 195, 24386 => 459, 24387 => 459, 24388 => 198, 24389 => 198,
			24390 => 198, 24391 => 198, 24393 => 197, 24394 => 197, 24399 => 459,
			24401 => 197, 24402 => 197, 24482 => 201, 24341 => 199, 24342 => 463,
			24343 => 463, 24358 => 201, 24360 => 463, 24361 => 463, 24367 => 463,
			24368 => 463, 24369 => 463, 24371 => 195, 24377 => 463, 24384 => 463,
			24395 => 463, 24396 => 463, 24397 => 463, 24398 => 463, 24400 => 463,
			24513 => 201, 24514 => 201, 24520 => 201, 24527 => 201, 24494 => 197,
			24495 => 197, 24498 => 202, 24499 => 202, 24500 => 202, 24501 => 202,
			24502 => 202, 24503 => 202, 24504 => 202, 24493 => 219, 24510 => 219,
			24515 => 203, 24516 => 203, 24517 => 226,
		);

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		$curated_matched_ids = $wpdb->get_col( "SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL" );

		$published = array();
		foreach ( $curated_matched_ids as $woo_id ) {
			$woo_id  = (int) $woo_id;
			$product = wc_get_product( $woo_id );
			if ( ! $product || $product->is_type( 'variation' ) ) {
				continue; // variations publish via their parent, never directly
			}
			if ( 'publish' === $product->get_status() ) {
				continue; // already live, nothing to do
			}

			if ( isset( $names[ $woo_id ] ) ) {
				$product->set_name( $names[ $woo_id ] );
			}
			if ( isset( $categories[ $woo_id ] ) ) {
				wp_set_object_terms( $woo_id, array( $categories[ $woo_id ] ), 'product_cat', false );
			}
			$product->set_status( 'publish' );
			$product->save();
			$published[] = $woo_id;
		}

		return $published;
	}

	public static function handle_finalize_publish_remaining() {
		check_admin_referer( 'saleson_finalize_publish_remaining' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$published = self::finalize_and_publish_ids();

		set_transient( 'saleson_finalize_publish_notice_' . get_current_user_id(), array(
			'count' => count( $published ),
		), 60 );

		self::redirect_back( array( 'tab' => 'matched' ) );
	}

	private static function render_finalize_publish_tool() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';

		$still_draft = 0;
		$curated_matched_ids = $wpdb->get_col( "SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL" );
		foreach ( $curated_matched_ids as $woo_id ) {
			$product = wc_get_product( (int) $woo_id );
			if ( $product && ! $product->is_type( 'variation' ) && 'publish' !== $product->get_status() ) {
				$still_draft++;
			}
		}

		$notice = get_transient( 'saleson_finalize_publish_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'saleson_finalize_publish_notice_' . get_current_user_id() );
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: %d: count published */
					__( 'Published %d products (names and categories corrected where known). Photos and descriptions still need to be added by the client.', 'saleson-woo-sync' ),
					$notice['count']
				) )
			);
		}

		if ( 0 === $still_draft ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;"
			onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'This publishes all %d remaining curated products now. Names and categories are corrected where known first; a couple of items with no confident category match will publish with their existing category unchanged. Photos and descriptions are NOT added by this - the client will add those later. Continue?', 'saleson-woo-sync' ), $still_draft ) ); ?>');">
			<?php wp_nonce_field( 'saleson_finalize_publish_remaining' ); ?>
			<input type="hidden" name="action" value="saleson_finalize_publish_remaining" />
			<?php submit_button( sprintf( __( 'Publish all %d remaining curated products now (photos/descriptions added later)', 'saleson-woo-sync' ), $still_draft ), 'primary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'Fixes product name (for the still-blank "Product" listings) and category (where confidently known) before publishing. Does not add photos or descriptions.', 'saleson-woo-sync' ); ?></p>
		</form>
		<?php
	}

	/**
	 * Creates a single draft WooCommerce product for one 'unmatched' mapping row.
	 * On-demand only - called for exactly the one row the reviewer clicked, never
	 * in bulk or automatically. Returns the new Woo product id, or null on failure.
	 */
	private static function create_product_for( $saleson_id ) {
		global $wpdb;
		$map_table = $wpdb->prefix . 'saleson_product_map';

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$map_table} WHERE saleson_product_id = %d AND mapping_status = 'unmatched'", $saleson_id )
		);
		if ( ! $row ) {
			return null;
		}

		// Real bug found 2026-07-30: "Reset to spreadsheet baseline" wipes every
		// Confirm/Reject/Create-as-product decision and re-imports the row as
		// 'unmatched' - but deliberately does NOT delete any WooCommerce product
		// already created for it (its own confirmation text says so). If a
		// product was created before a later reset, this row shows 'unmatched'
		// again even though a real, live, linked WooCommerce product already
		// exists - clicking "Create as product" again would silently create a
		// SECOND duplicate. Guard against that by checking for an existing
		// product stamped with this SalesOn id first and re-linking it instead
		// of creating a new one. (Found live: SalesOn 130407 / Woo 24478.)
		$existing_products = get_posts( array(
			'post_type'   => 'product',
			'post_status' => 'any',
			'numberposts' => 1,
			'meta_key'    => '_saleson_product_id',
			'meta_value'  => $saleson_id,
			'fields'      => 'ids',
		) );
		if ( ! empty( $existing_products ) ) {
			$existing_id = (int) $existing_products[0];
			$wpdb->update(
				$map_table,
				array(
					'mapping_status'  => 'matched',
					'woo_product_id' => $existing_id,
					'source'          => 'relinked_existing',
					'mapped_at'       => current_time( 'mysql' ),
				),
				array( 'saleson_product_id' => $saleson_id )
			);
			return $existing_id;
		}

		$name  = $row->saleson_name;
		$stock = is_numeric( $row->stock ) ? (int) $row->stock : null;
		$sku   = '';
		$price = null;

		$cache_table = $wpdb->prefix . 'saleson_stock_cache';
		if ( self::table_exists( $cache_table ) ) {
			$cache = $wpdb->get_row(
				$wpdb->prepare( "SELECT * FROM {$cache_table} WHERE saleson_product_id = %d", $saleson_id )
			);
			if ( $cache ) {
				if ( ! empty( $cache->name ) ) {
					$name = $cache->name;
				}
				if ( ! empty( $cache->product_code ) ) {
					$sku = $cache->product_code;
				}
				if ( null === $stock && isset( $cache->stock ) ) {
					$stock = (int) $cache->stock;
				}
			}
		}

		$price_table = $wpdb->prefix . 'saleson_price_tiers';
		if ( self::table_exists( $price_table ) && class_exists( 'Saleson_API' ) ) {
			$tier = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT rate FROM {$price_table} WHERE saleson_product_id = %d AND price_list_id = %d",
					$saleson_id,
					Saleson_API::PRICE_LIST_RETAIL
				)
			);
			if ( $tier && null !== $tier->rate ) {
				$price = $tier->rate;
			}
		}

		if ( empty( $name ) ) {
			$name = sprintf( 'SalesOn #%d', $saleson_id );
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'draft' );
		if ( $sku ) {
			$product->set_sku( $sku );
		}
		if ( null !== $price ) {
			$product->set_regular_price( $price );
		}
		if ( null !== $stock ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $stock );
			$product->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
		}

		$new_id = $product->save();

		if ( ! $new_id || is_wp_error( $new_id ) ) {
			return null;
		}

		// This product already exists in SalesOn (that's the whole point of this
		// screen - it's one of the 715 unmatched items, not a new one). Stamp the
		// real SalesOn id now so Saleson_Product_Creator's idempotency check
		// (get_post_meta(..., '_saleson_product_id')) correctly treats this as
		// already-linked when the product is later published/saved - otherwise it
		// would wrongly try to create a duplicate product in SalesOn on first publish.
		update_post_meta( $new_id, '_saleson_product_id', $saleson_id );

		if ( ! empty( $row->category ) ) {
			self::maybe_assign_category( $new_id, $row->category );
		}

		$wpdb->update(
			$map_table,
			array(
				'mapping_status'  => 'matched',
				'woo_product_id' => $new_id,
				'source'          => 'manual_create',
				'mapped_at'       => current_time( 'mysql' ),
			),
			array( 'saleson_product_id' => $saleson_id )
		);

		return $new_id;
	}

	private static function maybe_assign_category( $product_id, $category_name ) {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		$term = term_exists( $category_name, 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( $category_name, 'product_cat' );
		}
		if ( ! is_wp_error( $term ) && isset( $term['term_id'] ) ) {
			wp_set_object_terms( $product_id, (int) $term['term_id'], 'product_cat' );
		}
	}

	private static function table_exists( $table ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	private static function redirect_back( $extra_args = array() ) {
		$url = add_query_arg(
			array_merge( array( 'page' => self::PAGE_SLUG ), $extra_args ),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Shown on a product's own edit screen right after it was created from the
	 * Matcher's "Create as product" action - this is the cheapest moment to remind
	 * a human to add a photo, since they're already looking at the product. Soft
	 * reminder only, never blocks saving/publishing.
	 */
	public static function render_new_product_reminder() {
		if ( empty( $_GET['saleson_new_product'] ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'This product was created from a SalesOn item and has no photo or description yet - it is saved as a Draft. Add a photo before publishing so it does not become another blank listing.', 'saleson-woo-sync' )
		);
	}

	public static function render_curated_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$key    = 'saleson_matcher_curated_notice_' . get_current_user_id();
		$result = get_transient( $key );
		if ( false === $result ) {
			return;
		}
		delete_transient( $key );

		if ( empty( $result['ok'] ) ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
				esc_html__( 'Curated action failed:', 'saleson-woo-sync' ),
				esc_html( isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'saleson-woo-sync' ) )
			);
		} elseif ( isset( $result['count'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( 'Curated list imported - %d products marked as curated.', 'saleson-woo-sync' ), $result['count'] ) )
			);
		} elseif ( isset( $result['unpublished'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( __( '%d non-curated products set to Draft.', 'saleson-woo-sync' ), $result['unpublished'] ) )
			);
		}
	}

	public static function render_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$key    = 'saleson_matcher_notice_' . get_current_user_id();
		$result = get_transient( $key );
		if ( false === $result ) {
			return;
		}
		delete_transient( $key );

		if ( ! empty( $result['ok'] ) ) {
			$counts  = isset( $result['counts'] ) ? $result['counts'] : array();
			$summary = array();
			foreach ( $counts as $status => $c ) {
				$summary[] = sprintf( '%s: %d inserted, %d refreshed', $status, $c['inserted'], $c['refreshed'] );
			}
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
				esc_html__( 'Spreadsheet import complete.', 'saleson-woo-sync' ),
				esc_html( implode( ' | ', $summary ) )
			);
		} else {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
				esc_html__( 'Spreadsheet import failed:', 'saleson-woo-sync' ),
				esc_html( isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'saleson-woo-sync' ) )
			);
		}
	}

	// --- Rendering --------------------------------------------------------------

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'matched';
		if ( ! in_array( $tab, array( 'matched', 'unmatched', 'orphan', 'ignored', 'collisions' ), true ) ) {
			$tab = 'matched';
		}

		$collision_count = self::count_collision_groups();

		$tabs = array(
			'matched'   => __( 'Matched', 'saleson-woo-sync' ),
			'unmatched' => __( 'Unmatched (SalesOn only)', 'saleson-woo-sync' ),
			'orphan'    => __( 'Orphans (Woo only)', 'saleson-woo-sync' ),
			'ignored'   => __( 'Ignored (blank placeholders)', 'saleson-woo-sync' ),
			/* translators: %d: number of collision groups */
			'collisions' => $collision_count > 0 ? sprintf( __( 'Collisions (%d)', 'saleson-woo-sync' ), $collision_count ) : __( 'Collisions', 'saleson-woo-sync' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SalesOn Product Matcher', 'saleson-woo-sync' ); ?></h1>

			<p><?php esc_html_e( 'Reconciles SalesOn ERP products against this WooCommerce catalog, seeded from the Phase 0 spreadsheet review.', 'saleson-woo-sync' ); ?></p>

			<?php self::render_curated_summary_box(); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
				<?php wp_nonce_field( 'saleson_run_map_import' ); ?>
				<input type="hidden" name="action" value="saleson_run_map_import" />
				<?php submit_button( __( 'Run / Re-run Spreadsheet Import', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Safe to run more than once - existing rows keep their current status; only new rows and descriptive details are added.', 'saleson-woo-sync' ); ?></p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;"
				onsubmit="return confirm('<?php echo esc_js( __( 'This discards every Confirm/Reject/Create-as-product decision made so far and re-imports the spreadsheet fresh (the curated 220-item list is automatically re-applied afterward, so that scoping is preserved). It does NOT delete any WooCommerce products already created via \"Create as product\" - those need to be trashed manually if you want them gone too. Continue?', 'saleson-woo-sync' ) ); ?>');">
				<?php wp_nonce_field( 'saleson_reset_map' ); ?>
				<input type="hidden" name="action" value="saleson_reset_map" />
				<?php submit_button( __( 'Reset to spreadsheet baseline', 'saleson-woo-sync' ), 'delete', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Clears all reviewer decisions (Confirm/Reject/Create-as-product) and re-imports the spreadsheet as if for the first time. Does not touch any WooCommerce products already created - only the mapping table.', 'saleson-woo-sync' ); ?></p>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Curated catalog (client-approved list)', 'saleson-woo-sync' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Only SalesOn products in this list are treated as "should be on the website". Everything else is synced/tracked internally but ignored for storefront purposes.', 'saleson-woo-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'saleson_run_curated_import' ); ?>
				<input type="hidden" name="action" value="saleson_run_curated_import" />
				<?php submit_button( __( 'Import / Re-sync curated list', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Safe to re-run whenever the client sends an updated curated file - it always reflects the file exactly (unlike the spreadsheet import, this one resets is_curated on every run).', 'saleson-woo-sync' ); ?></p>
			</form>
			<?php self::render_non_curated_matched_tool(); ?>
			<?php self::render_finalize_publish_tool(); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ); ?>"
						class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>

			<?php
			switch ( $tab ) {
				case 'unmatched':
					self::render_unmatched_tab();
					break;
				case 'orphan':
					self::render_orphan_unpublish_tool();
					self::render_readonly_tab( 'orphan', array(
						'woo_name' => __( 'Woo Name', 'saleson-woo-sync' ),
						'sku'      => __( 'SKU', 'saleson-woo-sync' ),
						'category' => __( 'Category', 'saleson-woo-sync' ),
					) );
					break;
				case 'ignored':
					self::render_ignored_unpublish_tool();
					self::render_readonly_tab( 'ignored', array(
						'sku'      => __( 'SKU', 'saleson-woo-sync' ),
						'category' => __( 'Category', 'saleson-woo-sync' ),
						'notes'    => __( 'Notes', 'saleson-woo-sync' ),
					) );
					break;
				case 'collisions':
					self::render_collisions_tab();
					break;
				case 'matched':
				default:
					self::render_matched_tab();
					break;
			}
			?>
		</div>
		<?php
	}

	private static function get_paged() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	}

	private static function render_pagination( $total, $paged, $extra_args = array() ) {
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages <= 1 ) {
			return;
		}
		$base = add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $extra_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) );
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post( paginate_links( array(
			'base'      => $base,
			'format'    => '',
			'current'   => $paged,
			'total'     => $total_pages,
			'prev_text' => __( '&laquo; Previous', 'saleson-woo-sync' ),
			'next_text' => __( 'Next &raquo;', 'saleson-woo-sync' ),
		) ) );
		echo '</div></div>';
	}

	private static function render_matched_tab() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		$paged = self::get_paged();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		// Restricted to the client-approved curated catalog (items-28-07.xlsx) -
		// same reasoning as the Unmatched tab: only the 220 curated items are
		// "our concern" here, everything else is tracked but not our job to
		// review. Non-curated matched rows have their own dedicated bulk tool
		// above (render_non_curated_matched_tool), not this list.
		$total          = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'matched' AND is_curated = 1" );
		$total_excluded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'matched' AND is_curated = 0" );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE mapping_status = 'matched' AND is_curated = 1 ORDER BY saleson_product_id ASC LIMIT %d OFFSET %d",
				self::PER_PAGE,
				$offset
			)
		);

		printf( '<p>%s</p>', esc_html( sprintf( __( '%d matched rows total (curated catalog only).', 'saleson-woo-sync' ), $total ) ) );
		if ( $total_excluded > 0 ) {
			printf(
				'<p><em>%s</em></p>',
				esc_html( sprintf( __( '%d additional matched products exist outside the curated list and are intentionally hidden here - see "Matched products not in curated list" above to unpublish them.', 'saleson-woo-sync' ), $total_excluded ) )
			);
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'SalesOn ID', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'SalesOn Name', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Woo ID', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Woo Name', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Confidence', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'saleson-woo-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No matched rows yet - run the import above.', 'saleson-woo-sync' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->saleson_product_id ); ?></td>
					<td><?php echo esc_html( $row->saleson_name ); ?></td>
					<td><?php echo esc_html( $row->woo_product_id ); ?></td>
					<td><?php echo esc_html( $row->woo_name ); ?></td>
					<td><?php echo esc_html( $row->confidence ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'saleson_matcher_row' ); ?>
							<input type="hidden" name="action" value="saleson_matcher_confirm" />
							<input type="hidden" name="saleson_product_id" value="<?php echo esc_attr( $row->saleson_product_id ); ?>" />
							<?php submit_button( __( 'Confirm', 'saleson-woo-sync' ), 'primary small', 'submit', false ); ?>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
							<?php wp_nonce_field( 'saleson_matcher_row' ); ?>
							<input type="hidden" name="action" value="saleson_matcher_reject" />
							<input type="hidden" name="saleson_product_id" value="<?php echo esc_attr( $row->saleson_product_id ); ?>" />
							<?php submit_button( __( 'Reject', 'saleson-woo-sync' ), 'delete small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::render_pagination( $total, $paged, array( 'tab' => 'matched' ) );
	}

	private static function render_unmatched_tab() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		$paged = self::get_paged();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';

		$categories = $wpdb->get_col(
			"SELECT DISTINCT category FROM {$table} WHERE mapping_status = 'unmatched' AND is_curated = 1 AND category IS NOT NULL AND category != '' ORDER BY category ASC"
		);

		// Restricted to the client-approved curated catalog (items-28-07.xlsx) -
		// per instruction, everything outside this 220-item list is ignored, not
		// just "not yet reviewed". The excluded-count note below makes this
		// restriction visible rather than silently hiding rows.
		$where  = "mapping_status = 'unmatched' AND is_curated = 1";
		$params = array();
		if ( '' !== $category ) {
			$where   .= ' AND category = %s';
			$params[] = $category;
		}

		$total_excluded = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'unmatched' AND is_curated = 0" );

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$rows_sql    = "SELECT * FROM {$table} WHERE {$where} ORDER BY category ASC, saleson_name ASC LIMIT %d OFFSET %d";
		$rows_params = array_merge( $params, array( self::PER_PAGE, $offset ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ) );

		$bulk_notice = get_transient( 'saleson_bulk_create_notice_' . get_current_user_id() );
		if ( $bulk_notice ) {
			delete_transient( 'saleson_bulk_create_notice_' . get_current_user_id() );
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: created count, 2: failed count */
					__( 'Bulk create finished: %1$d draft products created, %2$d failed. Each still needs a photo added and publishing.', 'saleson-woo-sync' ),
					$bulk_notice['created'], $bulk_notice['failed']
				) )
			);
		}
		?>
		<?php if ( $total > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;"
				onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'This creates %d new draft WooCommerce products at once, one per remaining curated item that has no website presence at all. Safe to run - it will never touch a row that already has an existing product. Each new draft still needs a photo added before publishing. Continue?', 'saleson-woo-sync' ), $total ) ); ?>');">
				<?php wp_nonce_field( 'saleson_bulk_create_products' ); ?>
				<input type="hidden" name="action" value="saleson_matcher_bulk_create_products" />
				<?php submit_button( sprintf( __( 'Bulk create all %d remaining as draft products', 'saleson-woo-sync' ), $total ), 'primary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Creates every remaining row as a draft product in one pass (only rows with no existing WooCommerce product get created - anything already linked is skipped automatically). You still need to add a photo and publish each one afterward.', 'saleson-woo-sync' ); ?></p>
			</form>
		<?php endif; ?>
		<form method="get" style="margin:1em 0;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="unmatched" />
			<label for="saleson-category-filter"><?php esc_html_e( 'Filter by category:', 'saleson-woo-sync' ); ?></label>
			<select name="category" id="saleson-category-filter" onchange="this.form.submit()">
				<option value=""><?php esc_html_e( 'All categories', 'saleson-woo-sync' ); ?></option>
				<?php foreach ( $categories as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $category, $cat ); ?>><?php echo esc_html( $cat ); ?></option>
				<?php endforeach; ?>
			</select>
			<noscript><?php submit_button( __( 'Filter', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?></noscript>
		</form>

		<p>
			<?php echo esc_html( sprintf( __( '%d unmatched rows total (curated catalog only).', 'saleson-woo-sync' ), $total ) ); ?>
			<?php if ( $total_excluded > 0 ) : ?>
				<br /><em><?php echo esc_html( sprintf( __( '%d additional unmatched SalesOn items exist outside the curated list and are intentionally hidden here.', 'saleson-woo-sync' ), $total_excluded ) ); ?></em>
			<?php endif; ?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'SalesOn ID', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Name', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Category', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Stock', 'saleson-woo-sync' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'saleson-woo-sync' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'No unmatched rows.', 'saleson-woo-sync' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->saleson_product_id ); ?></td>
					<td><?php echo esc_html( $row->saleson_name ); ?></td>
					<td><?php echo esc_html( $row->category ); ?></td>
					<td><?php echo esc_html( $row->stock ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Create a new draft WooCommerce product for this single item?', 'saleson-woo-sync' ) ); ?>');">
							<?php wp_nonce_field( 'saleson_matcher_row' ); ?>
							<input type="hidden" name="action" value="saleson_matcher_create_product" />
							<input type="hidden" name="saleson_product_id" value="<?php echo esc_attr( $row->saleson_product_id ); ?>" />
							<?php submit_button( __( 'Create as product', 'saleson-woo-sync' ), 'primary small', 'submit', false ); ?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::render_pagination( $total, $paged, array( 'tab' => 'unmatched', 'category' => $category ) );
	}

	/**
	 * Read-only reference list for 'orphan' and 'ignored' rows - both are Woo-only
	 * (no real SalesOn id, keyed by a synthetic id - see Saleson_Map_Importer)
	 * and require no reconciliation action, just visibility.
	 */
	/**
	 * Per instruction (2026-07-28): orphans (real Woo products with no SalesOn
	 * match at all) can now also be unpublished - previously left alone
	 * deliberately pending review, but the goal of "only the curated 220 should
	 * be live" extends to these too. Draft, never delete - fully reversible.
	 */
	private static function render_orphan_unpublish_tool() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		// Filtered to still-'publish' posts, same reasoning as the non-curated
		// matched tool - otherwise this count (and the button it drives) never
		// shrinks even after orphans have already been unpublished.
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE m.mapping_status = 'orphan' AND m.woo_product_id IS NOT NULL AND p.post_status = 'publish'
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )"
		);

		if ( 0 === $count ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;"
			onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'This sets all %d orphan products (no SalesOn match at all) to Draft - not deleted, fully reversible. Continue?', 'saleson-woo-sync' ), $count ) ); ?>');">
			<?php wp_nonce_field( 'saleson_unpublish_orphans' ); ?>
			<input type="hidden" name="action" value="saleson_unpublish_orphans" />
			<?php submit_button( sprintf( __( 'Unpublish all %d orphans (set to Draft)', 'saleson-woo-sync' ), $count ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_ignored_unpublish_tool() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} m
			 INNER JOIN {$wpdb->posts} p ON p.ID = m.woo_product_id
			 WHERE m.mapping_status = 'ignored' AND m.woo_product_id IS NOT NULL AND p.post_status = 'publish'
			 AND m.woo_product_id NOT IN (
			 	SELECT woo_product_id FROM {$table} WHERE is_curated = 1 AND mapping_status = 'matched' AND woo_product_id IS NOT NULL
			 	UNION
			 	SELECT pp.post_parent FROM {$table} c
			 	INNER JOIN {$wpdb->posts} pp ON pp.ID = c.woo_product_id
			 	WHERE c.is_curated = 1 AND c.mapping_status = 'matched' AND c.woo_product_id IS NOT NULL AND pp.post_parent != 0
			 )"
		);

		if ( 0 === $count ) {
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;"
			onsubmit="return confirm('<?php echo esc_js( sprintf( __( 'This sets all %d blank-placeholder products to Draft - not deleted, fully reversible. Continue?', 'saleson-woo-sync' ), $count ) ); ?>');">
			<?php wp_nonce_field( 'saleson_unpublish_ignored' ); ?>
			<input type="hidden" name="action" value="saleson_unpublish_ignored" />
			<?php submit_button( sprintf( __( 'Unpublish all %d ignored/blank placeholders (set to Draft)', 'saleson-woo-sync' ), $count ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	private static function render_readonly_tab( $status, $extra_columns ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'saleson_product_map';
		$paged  = self::get_paged();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = %s", $status ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE mapping_status = %s ORDER BY woo_product_id ASC LIMIT %d OFFSET %d",
				$status,
				self::PER_PAGE,
				$offset
			)
		);

		printf( '<p>%s</p>', esc_html( sprintf( __( '%d rows total.', 'saleson-woo-sync' ), $total ) ) );
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Woo ID', 'saleson-woo-sync' ); ?></th>
					<?php foreach ( $extra_columns as $label ) : ?>
						<th><?php echo esc_html( $label ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $rows ) ) : ?>
				<tr><td colspan="<?php echo esc_attr( count( $extra_columns ) + 1 ); ?>"><?php esc_html_e( 'No rows.', 'saleson-woo-sync' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row->woo_product_id ); ?></td>
					<?php foreach ( array_keys( $extra_columns ) as $col ) : ?>
						<td><?php echo esc_html( $row->$col ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		self::render_pagination( $total, $paged, array( 'tab' => $status ) );
	}
}
