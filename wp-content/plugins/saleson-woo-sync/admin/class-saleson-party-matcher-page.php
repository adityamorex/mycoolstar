<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Phase 2, step 1 UI: review screen for wp_saleson_party_map, seeded by
 * Saleson_Party_Importer. Read-only reconciliation view for now - no account
 * creation happens from this screen yet (that is a deliberate later step,
 * same "map first, review, create on demand" discipline used for products).
 */
class Saleson_Party_Matcher_Page {

	const PAGE_SLUG = 'saleson-parties';
	const PER_PAGE   = 30;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_saleson_run_party_import', array( __CLASS__, 'handle_run_import' ) );
		add_action( 'admin_post_saleson_create_party_accounts', array( __CLASS__, 'handle_create_accounts' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			Saleson_Admin_Menu::PARENT_SLUG,
			__( 'Customers', 'saleson-woo-sync' ),
			__( 'Customers', 'saleson-woo-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function handle_run_import() {
		check_admin_referer( 'saleson_run_party_import' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$result = Saleson_Party_Importer::run();
		set_transient( 'saleson_party_import_notice_' . get_current_user_id(), $result, 60 );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_create_accounts() {
		check_admin_referer( 'saleson_create_party_accounts' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$group = isset( $_POST['group_name'] ) ? sanitize_text_field( wp_unslash( $_POST['group_name'] ) ) : '';
		$group = $group ? $group : null;

		$result = Saleson_Party_Account_Creator::create_batch( Saleson_Party_Account_Creator::DEFAULT_BATCH_SIZE, $group );
		set_transient( 'saleson_party_create_notice_' . get_current_user_id(), $result, 60 );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'created' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function table_exists( $table ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_party_map';

		$user_id = get_current_user_id();
		$notice  = get_transient( 'saleson_party_import_notice_' . $user_id );
		if ( $notice ) {
			delete_transient( 'saleson_party_import_notice_' . $user_id );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'SalesOn Parties', 'saleson-woo-sync' ) . '</h1>';
		echo '<p>' . esc_html__( 'Reconciles SalesOn parties (customers/dealers) against existing WordPress users. Read-only review screen - creating accounts is a separate, deliberate later step.', 'saleson-woo-sync' ) . '</p>';

		if ( $notice ) {
			if ( ! empty( $notice['ok'] ) ) {
				printf(
					'<div class="notice notice-success"><p>%s</p></div>',
					esc_html( sprintf(
						/* translators: 1: processed, 2: matched, 3: unmatched, 4: excluded */
						__( 'Import complete: %1$d parties processed - %2$d matched to an existing user, %3$d unmatched (no account yet), %4$d excluded (suppliers/test data).', 'saleson-woo-sync' ),
						$notice['processed'], $notice['matched'], $notice['unmatched'], $notice['excluded']
					) )
				);
			} else {
				printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $notice['error'] ) );
			}
		}

		$create_notice = get_transient( 'saleson_party_create_notice_' . $user_id );
		if ( $create_notice ) {
			delete_transient( 'saleson_party_create_notice_' . $user_id );
			printf(
				'<div class="notice notice-success"><p>%s</p></div>',
				esc_html( sprintf(
					/* translators: 1: created, 2: skipped, 3: remaining */
					__( 'Account creation batch complete: %1$d accounts created, %2$d skipped (collision/invalid - check Excluded tab), %3$d still unmatched.', 'saleson-woo-sync' ),
					$create_notice['created'], $create_notice['skipped'], $create_notice['remaining']
				) )
			);
		}

		if ( ! self::table_exists( $table ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The party mapping table does not exist yet - deactivate and reactivate the plugin once to create it, then come back here.', 'saleson-woo-sync' ) . '</p></div>';
			echo '</div>';
			return;
		}

		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;">
			<?php wp_nonce_field( 'saleson_run_party_import' ); ?>
			<input type="hidden" name="action" value="saleson_run_party_import" />
			<?php submit_button( __( 'Run / Re-run Party Import', 'saleson-woo-sync' ), 'primary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'Safe to re-run any time - re-pulls every SalesOn party fresh and re-checks for a matching WordPress user. Never creates or deletes a WordPress account.', 'saleson-woo-sync' ); ?></p>
		</form>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 1em 0;">
			<?php wp_nonce_field( 'saleson_create_party_accounts' ); ?>
			<input type="hidden" name="action" value="saleson_create_party_accounts" />
			<label for="saleson-group-filter"><?php esc_html_e( 'Group (optional):', 'saleson-woo-sync' ); ?></label>
			<select name="group_name" id="saleson-group-filter">
				<option value=""><?php esc_html_e( 'All groups', 'saleson-woo-sync' ); ?></option>
				<option value="DEALER">Dealer</option>
				<option value="DISTRIBUTORS">Distributor</option>
				<option value="SUPERMART">Supermart / Superstockist</option>
				<option value="CUSTOMERS">Customers</option>
			</select>
			<?php submit_button( sprintf( __( 'Create next %d accounts', 'saleson-woo-sync' ), Saleson_Party_Account_Creator::DEFAULT_BATCH_SIZE ), 'primary', 'submit', false ); ?>
			<p class="description"><?php esc_html_e( 'Creates real WordPress accounts for unmatched parties (username = mobile number, auto-generated password shown on the Created tab). Safe to click repeatedly - only processes rows still unmatched. Recommended order: Dealer, then Distributor, then Supermart, then Customers.', 'saleson-woo-sync' ); ?></p>
		</form>
		<?php

		self::render_summary_box();

		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'matched';
		if ( ! in_array( $tab, array( 'matched', 'created', 'unmatched', 'excluded' ), true ) ) {
			$tab = 'matched';
		}

		$tabs = array(
			'matched'   => __( 'Matched to existing user', 'saleson-woo-sync' ),
			'created'   => __( 'Created (new accounts)', 'saleson-woo-sync' ),
			'unmatched' => __( 'Unmatched (no account yet)', 'saleson-woo-sync' ),
			'excluded'  => __( 'Excluded (suppliers/test)', 'saleson-woo-sync' ),
		);

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$class = ( $tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $slug ), admin_url( 'admin.php' ) ) ),
				esc_attr( $class ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		self::render_table( $tab );

		echo '</div>';
	}

	private static function render_summary_box() {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_party_map';

		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$matched   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'matched'" );
		$created   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'created'" );
		$unmatched = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'unmatched'" );
		$excluded  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = 'excluded'" );
		$suppliers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'supplier'" );
		$test      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'test_group'" );
		$cancelled = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'cancelled_record'" );
		$dummy     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'dummy_record'" );
		$no_mobile   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'no_usable_mobile'" );
		$collision   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'username_collision'" );
		$create_failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE exclude_reason = 'wp_insert_user_failed'" );
		$no_group  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE ( group_name IS NULL OR group_name = '' ) AND mapping_status != 'excluded'" );
		$placeholder = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_placeholder_email = 1 AND mapping_status = 'unmatched'" );

		if ( 0 === $total ) {
			echo '<p><em>' . esc_html__( 'No parties imported yet - click "Run / Re-run Party Import" above.', 'saleson-woo-sync' ) . '</em></p>';
			return;
		}
		?>
		<div class="notice notice-info" style="padding: 10px 16px; margin: 1em 0;">
			<p style="margin: 0;"><strong><?php esc_html_e( 'SalesOn party reconciliation status:', 'saleson-woo-sync' ); ?></strong></p>
			<p style="margin: 6px 0 0;">
				<?php
				echo esc_html( sprintf(
					/* translators: 1: total, 2: matched, 3: created, 4: unmatched, 5: excluded */
					__( '%1$d parties total - %2$d already matched to an existing website account, %3$d new accounts created, %4$d still have no account, %5$d excluded (suppliers/test data).', 'saleson-woo-sync' ),
					$total, $matched, $created, $unmatched, $excluded
				) );
				?>
				<br />
				<?php
				echo esc_html( sprintf(
					/* translators: 1: supplier count, 2: test count, 3: cancelled count, 4: dummy count */
					__( 'Excluded breakdown: %1$d suppliers (not customers), %2$d test-group records, %3$d cancelled/voided records, %4$d dummy/test records.', 'saleson-woo-sync' ),
					$suppliers, $test, $cancelled, $dummy
				) );
				?>
				<?php if ( $no_mobile > 0 || $collision > 0 || $create_failed > 0 ) : ?>
					<br />
					<?php
					echo esc_html( sprintf(
						/* translators: 1: no-mobile count, 2: collision count, 3: creation-failure count */
						__( 'Account-creation exclusions: %1$d had no usable mobile number and no real email (cannot log in without one), %2$d skipped due to a duplicate mobile/email already used by another account, %3$d failed to create for another reason (see error log). Check the Excluded tab for details.', 'saleson-woo-sync' ),
						$no_mobile, $collision, $create_failed
					) );
					?>
				<?php endif; ?>
				<?php if ( $no_group > 0 ) : ?>
					<br /><em><?php echo esc_html( sprintf( __( '%d parties have no group assigned in SalesOn at all - unclear tier, worth asking the client what these are.', 'saleson-woo-sync' ), $no_group ) ); ?></em>
				<?php endif; ?>
				<?php if ( $placeholder > 0 ) : ?>
					<br /><em><?php echo esc_html( sprintf( __( '%d of the unmatched parties have no real email in SalesOn - a placeholder (party-{id}@placeholder.mycoolstar.com) is shown as a preview only, nothing has been created.', 'saleson-woo-sync' ), $placeholder ) ); ?></em>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private static function get_paged() {
		return isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	}

	private static function render_table( $tab ) {
		global $wpdb;
		$table  = $wpdb->prefix . 'saleson_party_map';
		$paged  = self::get_paged();
		$offset = ( $paged - 1 ) * self::PER_PAGE;

		$status = $tab;
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE mapping_status = %s", $status ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE mapping_status = %s ORDER BY saleson_name ASC LIMIT %d OFFSET %d",
				$status, self::PER_PAGE, $offset
			)
		);

		printf( '<p>%s</p>', esc_html( sprintf( __( '%d rows.', 'saleson-woo-sync' ), $total ) ) );

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'SalesOn ID', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Name', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Mobile', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'SalesOn Group', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer Type (mapped)', 'saleson-woo-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Credit Limit', 'saleson-woo-sync' ) . '</th>';
		if ( 'matched' === $status ) {
			echo '<th>' . esc_html__( 'Website User', 'saleson-woo-sync' ) . '</th>';
		} elseif ( 'created' === $status ) {
			echo '<th>' . esc_html__( 'Website User', 'saleson-woo-sync' ) . '</th>';
			echo '<th>' . esc_html__( 'Password', 'saleson-woo-sync' ) . '</th>';
		} elseif ( 'unmatched' === $status ) {
			echo '<th>' . esc_html__( 'Login Email (preview only)', 'saleson-woo-sync' ) . '</th>';
		} else {
			echo '<th>' . esc_html__( 'Excluded Because', 'saleson-woo-sync' ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			echo '<tr><td colspan="7">' . esc_html__( 'No rows.', 'saleson-woo-sync' ) . '</td></tr>';
		}

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row->saleson_party_id ) . '</td>';
			echo '<td>' . esc_html( $row->saleson_name ) . '</td>';
			echo '<td>' . esc_html( $row->mobile ) . '</td>';
			echo '<td>' . esc_html( $row->group_name ? $row->group_name : '-' ) . '</td>';
			echo '<td>' . esc_html( $row->customer_type ? $row->customer_type : '(none - review)' ) . '</td>';
			echo '<td>' . esc_html( $row->credit_limit ) . '</td>';
			if ( 'matched' === $status ) {
				$user_edit = get_edit_user_link( $row->woo_user_id );
				echo '<td>' . ( $user_edit ? '<a href="' . esc_url( $user_edit ) . '">#' . esc_html( $row->woo_user_id ) . ' (' . esc_html( $row->woo_login_email ) . ')</a>' : esc_html( $row->woo_login_email ) ) . '</td>';
			} elseif ( 'created' === $status ) {
				$user_edit = get_edit_user_link( $row->woo_user_id );
				echo '<td>' . ( $user_edit ? '<a href="' . esc_url( $user_edit ) . '">#' . esc_html( $row->woo_user_id ) . ' (' . esc_html( $row->woo_login_email ) . ')</a>' : esc_html( $row->woo_login_email ) ) . '</td>';
				echo '<td><code>' . esc_html( $row->generated_password ) . '</code></td>';
			} elseif ( 'unmatched' === $status ) {
				echo '<td>' . esc_html( $row->woo_login_email ) . ( $row->is_placeholder_email ? ' <em>(placeholder)</em>' : '' ) . '</td>';
			} else {
				echo '<td>' . esc_html( $row->exclude_reason ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		$total_pages = (int) ceil( $total / self::PER_PAGE );
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo wp_kses_post( paginate_links( array(
				'base'    => add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $total_pages,
			) ) );
			echo '</div></div>';
		}
	}
}
