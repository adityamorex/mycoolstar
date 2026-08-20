<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One top-level "SalesOn" menu instead of six loose items scattered through
 * WooCommerce's submenu (added 2026-08-20 - the WooCommerce menu had grown to
 * the point where SalesOn Pricing / Stock / Sync / Matcher / Parties / Products
 * were buried among WooCommerce's own entries and read as unrelated tools).
 *
 * Grouped the way staff actually work, mirroring how SalesOn's own sidebar is
 * organised, with the daily screens first and the engineer-facing ones last:
 *
 *   SalesOn
 *     Products     - the product console (daily)
 *     Customers    - parties/dealer accounts (daily)
 *     Sync Status  - is everything running, run it now (occasional)
 *     Pricing      - push a tier price to SalesOn (engineer/staff tool)
 *     Stock        - push a stock correction to SalesOn (engineer/staff tool)
 *     Matcher      - product mapping internals (engineer only)
 *
 * Orders deliberately stay in WooCommerce's own menu, where staff already
 * expect them and where WooCommerce's native order screens live.
 *
 * Registered at priority 9 so the parent exists before any submenu attaches at
 * the default priority 10. The parent's slug is the Products page's slug, so
 * clicking "SalesOn" lands on the most-used screen rather than an empty shell
 * or a duplicated first entry.
 */
class Saleson_Admin_Menu {

	const PARENT_SLUG = 'saleson-products';
	const CAPABILITY  = 'manage_woocommerce';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_parent' ), 9 );
	}

	public static function register_parent() {
		add_menu_page(
			__( 'SalesOn', 'saleson-woo-sync' ),
			__( 'SalesOn', 'saleson-woo-sync' ),
			self::CAPABILITY,
			self::PARENT_SLUG,
			array( 'Saleson_Products_Page', 'render_page' ),
			'dashicons-store',
			56 // just below WooCommerce/Products, above Appearance
		);
	}
}
