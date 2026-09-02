<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Admin settings screen for the SalesOn <-> WooCommerce sync plugin.
 *
 * - Stores API token / company id / cron interval via the WP Settings API.
 * - Shows a read-only note about the (single, hardcoded) warehouse.
 * - Provides a "Test Connection" button that hits SalesOn's rate-list report.
 * - Surfaces the last sync run (endpoint, status, items processed, error).
 */
class Saleson_Settings_Page {

	const OPTION_GROUP = 'saleson_woo_sync_settings';
	const PAGE_SLUG    = 'saleson-woo-sync';
	const TEST_TRANSIENT_PREFIX = 'saleson_test_connection_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_saleson_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_saleson_run_sync_now', array( __CLASS__, 'handle_run_sync_now' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
	}

	/**
	 * Menu placement: WooCommerce submenu when WooCommerce is active (this plugin
	 * is meaningless without it), falling back to a top-level menu otherwise so the
	 * page is still reachable on setups where WooCommerce hasn't loaded yet.
	 */
	public static function register_menu() {
		add_submenu_page(
			Saleson_Admin_Menu::PARENT_SLUG,
			__( 'Sync Status', 'saleson-woo-sync' ),
			__( 'Sync Status', 'saleson-woo-sync' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting( self::OPTION_GROUP, 'saleson_api_token', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => '',
		) );

		register_setting( self::OPTION_GROUP, 'saleson_company_id', array(
			'type'              => 'string',
			'sanitize_callback' => array( __CLASS__, 'sanitize_company_id' ),
			'default'           => '5249',
		) );

		register_setting( self::OPTION_GROUP, 'saleson_cron_interval_minutes', array(
			'type'              => 'integer',
			'sanitize_callback' => array( __CLASS__, 'sanitize_cron_interval' ),
			'default'           => 15,
		) );

		add_settings_section(
			'saleson_main_section',
			__( 'Connection Settings', 'saleson-woo-sync' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			'saleson_api_token',
			__( 'SalesOn API Token', 'saleson-woo-sync' ),
			array( __CLASS__, 'render_field_api_token' ),
			self::PAGE_SLUG,
			'saleson_main_section'
		);

		add_settings_field(
			'saleson_company_id',
			__( 'Company ID', 'saleson-woo-sync' ),
			array( __CLASS__, 'render_field_company_id' ),
			self::PAGE_SLUG,
			'saleson_main_section'
		);

		add_settings_field(
			'saleson_cron_interval_minutes',
			__( 'Cron Interval (minutes)', 'saleson-woo-sync' ),
			array( __CLASS__, 'render_field_cron_interval' ),
			self::PAGE_SLUG,
			'saleson_main_section'
		);

		add_settings_field(
			'saleson_warehouse_info',
			__( 'Warehouse', 'saleson-woo-sync' ),
			array( __CLASS__, 'render_field_warehouse_info' ),
			self::PAGE_SLUG,
			'saleson_main_section'
		);
	}

	public static function sanitize_company_id( $value ) {
		$value = sanitize_text_field( $value );
		return preg_replace( '/[^0-9]/', '', $value );
	}

	public static function sanitize_cron_interval( $value ) {
		$value = absint( $value );
		return $value > 0 ? $value : 15;
	}

	// --- Field renderers -------------------------------------------------

	public static function render_field_api_token() {
		$value = get_option( 'saleson_api_token', '' );
		?>
		<input
			type="password"
			id="saleson_api_token"
			name="saleson_api_token"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="off"
		/>
		<p class="description"><?php esc_html_e( 'Bearer token used to authenticate all SalesOn API requests.', 'saleson-woo-sync' ); ?></p>
		<?php
	}

	public static function render_field_company_id() {
		$value = get_option( 'saleson_company_id', '5249' );
		?>
		<input
			type="text"
			id="saleson_company_id"
			name="saleson_company_id"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
		/>
		<?php
	}

	public static function render_field_cron_interval() {
		$value = get_option( 'saleson_cron_interval_minutes', 15 );
		?>
		<input
			type="number"
			min="1"
			step="1"
			id="saleson_cron_interval_minutes"
			name="saleson_cron_interval_minutes"
			value="<?php echo esc_attr( $value ); ?>"
			class="small-text"
		/>
		<?php esc_html_e( 'minutes', 'saleson-woo-sync' ); ?>
		<?php
	}

	public static function render_field_warehouse_info() {
		?>
		<p>
			<strong><?php esc_html_e( 'Warehouse:', 'saleson-woo-sync' ); ?></strong>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: warehouse id */
					__( 'HB Akeda Dungar (id %d) — the only real warehouse, confirmed during Phase 0 discovery. Not configurable.', 'saleson-woo-sync' ),
					Saleson_API::WAREHOUSE_ID
				)
			);
			?>
		</p>
		<?php
	}

	// --- Test connection ---------------------------------------------------

	public static function handle_test_connection() {
		check_admin_referer( 'saleson_test_connection' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		$api    = new Saleson_API();
		$result = $api->get( 'reports/rate-list', array( 'page_size' => 1 ) );

		$user_id = get_current_user_id();
		$key     = self::TEST_TRANSIENT_PREFIX . $user_id;

		if ( ! empty( $result['ok'] ) ) {
			set_transient( $key, array(
				'success' => true,
				'status'  => $result['status'],
			), 60 );
		} else {
			set_transient( $key, array(
				'success' => false,
				'status'  => isset( $result['status'] ) ? $result['status'] : 0,
				'error'   => isset( $result['error'] ) ? $result['error'] : __( 'Unknown error', 'saleson-woo-sync' ),
			), 60 );
		}

		$redirect_url = add_query_arg(
			array(
				'page'                    => self::PAGE_SLUG,
				'saleson_test_connection' => '1',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	public static function render_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		if ( empty( $_GET['saleson_test_connection'] ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$key     = self::TEST_TRANSIENT_PREFIX . $user_id;
		$result  = get_transient( $key );

		if ( false === $result ) {
			return;
		}

		delete_transient( $key );

		if ( ! empty( $result['success'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Connected — SalesOn responded successfully.', 'saleson-woo-sync' )
			);
		} else {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: HTTP status code, 2: error message */
						__( 'Connection failed (HTTP %1$s): %2$s', 'saleson-woo-sync' ),
						$result['status'],
						$result['error']
					)
				)
			);
		}
	}

	// --- Last run summary ----------------------------------------------------

	private static function render_last_run() {
		if ( ! class_exists( 'Saleson_Logger' ) ) {
			return;
		}

		$run = Saleson_Logger::last_run();

		if ( ! $run ) {
			?>
			<div class="notice notice-info inline">
				<p><?php esc_html_e( 'Last sync: no sync runs recorded yet.', 'saleson-woo-sync' ); ?></p>
			</div>
			<?php
			return;
		}

		$status       = isset( $run->status ) ? $run->status : '';
		$is_failed    = ( 'failed' === $status );
		$notice_class = $is_failed ? 'notice-error' : ( 'running' === $status ? 'notice-warning' : 'notice-success' );

		$when = ! empty( $run->run_finished_at ) ? $run->run_finished_at : $run->run_started_at;
		?>
		<div class="notice <?php echo esc_attr( $notice_class ); ?> inline">
			<p>
				<strong><?php esc_html_e( 'Last sync:', 'saleson-woo-sync' ); ?></strong>
				<?php
				printf(
					/* translators: 1: endpoint, 2: date/time, 3: status, 4: items processed */
					esc_html__( '%1$s ran at %2$s — status: %3$s — items processed: %4$s', 'saleson-woo-sync' ),
					esc_html( $run->endpoint ),
					esc_html( $when ),
					esc_html( strtoupper( $status ) ),
					esc_html( isset( $run->items_processed ) ? $run->items_processed : '0' )
				);
				?>
			</p>
			<?php if ( $is_failed && ! empty( $run->error_message ) ) : ?>
				<p><strong><?php esc_html_e( 'Error:', 'saleson-woo-sync' ); ?></strong> <?php echo esc_html( $run->error_message ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	// --- Page render -----------------------------------------------------------

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SalesOn Sync', 'saleson-woo-sync' ); ?></h1>

			<?php self::render_last_run(); ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save Settings', 'saleson-woo-sync' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Test Connection', 'saleson-woo-sync' ); ?></h2>
			<p><?php esc_html_e( 'Checks that the token and company ID above can successfully call the SalesOn API.', 'saleson-woo-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'saleson_test_connection' ); ?>
				<input type="hidden" name="action" value="saleson_test_connection" />
				<?php submit_button( __( 'Test Connection', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 10px;">
				<?php wp_nonce_field( 'saleson_run_sync_now' ); ?>
				<input type="hidden" name="action" value="saleson_run_sync_now" />
				<?php submit_button( __( 'Run Sync Now', 'saleson-woo-sync' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Runs the full stock/price/tiers sync immediately, instead of waiting for the next scheduled cron tick.', 'saleson-woo-sync' ); ?></p>
			</form>

			<?php self::render_cron_endpoints(); ?>
		</div>
		<?php
	}

	// --- Split cron endpoints (2026-09-02) --------------------------------------

	private static function render_cron_endpoints() {
		if ( ! class_exists( 'Saleson_Cron_Endpoints' ) ) {
			return;
		}
		?>
		<hr />
		<h2><?php esc_html_e( 'Scheduled Sync (hPanel Cron Job)', 'saleson-woo-sync' ); ?></h2>
		<p>
			<?php esc_html_e( 'This one URL is all you need in hPanel. It runs almost instantly itself - it just fires off each real sync step as its own separate background request (spaced a couple seconds apart), so the actual work is still spread across several small, isolated processes instead of one large one, without needing a separate cron job per step (most hosting plans cap the number of cron jobs allowed).', 'saleson-woo-sync' ); ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Set up ONE hPanel cron job, every 15 minutes:', 'saleson-woo-sync' ); ?></strong><br />
			<code style="user-select:all;">wget -q -O /dev/null "<?php echo esc_url( Saleson_Cron_Endpoints::get_endpoint_url( 'dispatch-all' ) ); ?>"</code>
		</p>
		<p class="description">
			<?php esc_html_e( 'Replace any existing cron job that hits this plugin (including one that hits wp-cron.php directly) with this single one.', 'saleson-woo-sync' ); ?>
		</p>

		<details style="margin-top: 14px;">
			<summary><?php esc_html_e( 'Individual step URLs (advanced - for testing one step manually, not for regular scheduling)', 'saleson-woo-sync' ); ?></summary>
			<table class="widefat" style="max-width: 900px; margin-top: 8px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Step', 'saleson-woo-sync' ); ?></th>
						<th><?php esc_html_e( 'URL', 'saleson-woo-sync' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$labels = array(
						'stock-price'    => __( 'Stock, price & price tiers', 'saleson-woo-sync' ),
						'order-status'   => __( 'Order status & invoice sync', 'saleson-woo-sync' ),
						'order-import'   => __( 'Import SalesOn-direct orders', 'saleson-woo-sync' ),
						'product-import' => __( 'Auto-import new products', 'saleson-woo-sync' ),
						'party-balance'  => __( 'Party credit/balance refresh', 'saleson-woo-sync' ),
					);
					foreach ( $labels as $step => $label ) {
						printf(
							'<tr><td>%s</td><td><code style="user-select:all;">%s</code></td></tr>',
							esc_html( $label ),
							esc_url( Saleson_Cron_Endpoints::get_endpoint_url( $step ) )
						);
					}
					?>
				</tbody>
			</table>
		</details>

		<p class="description" style="margin-top: 10px;">
			<?php esc_html_e( 'The token in these URLs is a secret - anyone with it can trigger a sync step. It is not shown anywhere else and does not need to be memorized, just pasted once into hPanel.', 'saleson-woo-sync' ); ?>
		</p>
		<?php
	}

	public static function handle_run_sync_now() {
		check_admin_referer( 'saleson_run_sync_now' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'saleson-woo-sync' ) );
		}

		// Runs every step in sequence, unlike the automatic cron path (which
		// now hits each step as its own separate hPanel-triggered request -
		// see Saleson_Cron_Endpoints, 2026-09-02). Fine here: a staff click
		// is a rare, deliberate, one-off action, not a recurring background
		// load competing with real traffic for CPU/memory every few minutes.
		if ( class_exists( 'Saleson_Stock_Sync' ) ) {
			Saleson_Stock_Sync::run_stock_price_only();
		}
		if ( class_exists( 'Saleson_Party_Balance_Sync' ) ) {
			Saleson_Party_Balance_Sync::run();
		}
		if ( class_exists( 'Saleson_Order_Status_Sync' ) ) {
			Saleson_Order_Status_Sync::run();
		}
		if ( class_exists( 'Saleson_Order_Importer' ) ) {
			Saleson_Order_Importer::sync_from_saleson();
		}
		if ( class_exists( 'Saleson_Product_Importer' ) ) {
			Saleson_Product_Importer::auto_import_new();
		}

		wp_safe_redirect( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) );
		exit;
	}
}
