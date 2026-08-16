<?php
/**
 * Plugin Name: SalesOn WooCommerce Sync
 * Description: Two-way sync between SalesOn ERP and this WooCommerce store - stock, pricing tiers, product mapping, and product creation.
 * Version: 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SALESON_WOO_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-api.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-logger.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-db.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-stock-sync.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-price-sync-pull.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-map-importer.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-price-writeback.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-stock-writeback.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-product-creator.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-party-importer.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-party-account-creator.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-party-balance-sync.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-order-submitter.php';
require_once SALESON_WOO_SYNC_DIR . 'includes/class-saleson-order-status-sync.php';
require_once SALESON_WOO_SYNC_DIR . 'admin/class-saleson-settings-page.php';
require_once SALESON_WOO_SYNC_DIR . 'admin/class-saleson-matcher-page.php';
require_once SALESON_WOO_SYNC_DIR . 'admin/class-saleson-party-matcher-page.php';

register_activation_hook( __FILE__, array( 'Saleson_DB', 'install' ) );

register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'saleson_woo_sync_cron' );
} );

add_action( 'plugins_loaded', function () {
	Saleson_Settings_Page::init();
	Saleson_Matcher_Page::init();
	Saleson_Party_Matcher_Page::init();
	Saleson_Stock_Sync::init();
	Saleson_Product_Creator::init();
	Saleson_Order_Submitter::init();
} );
