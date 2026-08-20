<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Creates website listings for SalesOn products - the "SalesOn is the master
 * product database" half of the 2026-08-20 direction: staff add a product in
 * SalesOn, it appears on the website automatically as a draft, staff add a
 * photo and description, then publish it.
 *
 * Two entry points, one shared creation routine:
 *  - auto_import_new()  - runs in the cron cycle, picks up genuinely NEW
 *                         SalesOn products on its own.
 *  - create_for()       - a single product on demand, from the "Add to
 *                         website" button on the Products console.
 *
 * Deliberately creates DRAFT products, never published (client decision
 * 2026-08-20): nothing reaches the storefront without a human looking at it
 * first, because SalesOn holds no images or descriptions and an auto-published
 * product would look broken to a customer.
 */
class Saleson_Product_Importer {

	const META_KEY = '_saleson_product_id';

	/**
	 * Only products first seen AFTER this many days ago are treated as "new"
	 * and auto-imported. Everything older is the pre-existing non-curated
	 * backlog (~680 spare parts, "CANCEL..." records and duplicates that the
	 * Phase 1 curation deliberately left off the website) - auto-creating
	 * those would bury the real new arrivals in junk. They stay available on
	 * demand via the console's "New in SalesOn" tab instead.
	 */
	const NEW_PRODUCT_WINDOW_DAYS = 7;

	/**
	 * @return array{created:int, skipped:int}
	 */
	public static function auto_import_new() {
		$log_id  = Saleson_Logger::start( 'product_auto_import' );
		$created = 0;
		$skipped = 0;

		try {
			global $wpdb;
			$map = $wpdb->prefix . 'saleson_product_map';

			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::NEW_PRODUCT_WINDOW_DAYS * DAY_IN_SECONDS ) );

			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT saleson_product_id FROM {$map}
				 WHERE woo_product_id IS NULL
				   AND mapping_status = 'unmatched'
				   AND first_seen_at IS NOT NULL
				   AND first_seen_at >= %s
				 ORDER BY first_seen_at ASC
				 LIMIT 50",
				$cutoff
			) );

			foreach ( $ids as $saleson_id ) {
				$result = self::create_for( (int) $saleson_id );
				if ( $result['ok'] ) {
					$created++;
				} else {
					$skipped++;
				}
			}

			Saleson_Logger::finish( $log_id, $created, 0, null );
		} catch ( \Throwable $e ) {
			Saleson_Logger::finish( $log_id, $created, 1, $e->getMessage() );
		}

		return array( 'created' => $created, 'skipped' => $skipped );
	}

	/**
	 * Creates one draft WooCommerce product for a SalesOn product and links
	 * the two together.
	 *
	 * @return array{ok:bool, woo_product_id?:int, error?:string}
	 */
	public static function create_for( $saleson_product_id ) {
		global $wpdb;
		$saleson_product_id = (int) $saleson_product_id;
		$map                = $wpdb->prefix . 'saleson_product_map';
		$cache              = $wpdb->prefix . 'saleson_stock_cache';

		if ( ! function_exists( 'wc_get_product' ) ) {
			return array( 'ok' => false, 'error' => 'WooCommerce not active.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT m.*, c.name AS cache_name, c.stock AS cache_stock, c.sell_price
			 FROM {$map} m
			 LEFT JOIN {$cache} c ON c.saleson_product_id = m.saleson_product_id
			 WHERE m.saleson_product_id = %d",
			$saleson_product_id
		) );

		if ( ! $row ) {
			return array( 'ok' => false, 'error' => 'Not a known SalesOn product.' );
		}

		if ( $row->woo_product_id ) {
			return array( 'ok' => false, 'error' => 'Already linked to a website product.' );
		}

		// Same duplicate guard the Matcher uses: a previous run (or a reset of
		// the mapping table) may have already created a website product for
		// this SalesOn id. Re-link to it rather than creating a second listing.
		$existing = get_posts( array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'meta_key'       => self::META_KEY,
			'meta_value'     => $saleson_product_id,
			'numberposts'    => 1,
			'fields'         => 'ids',
		) );

		if ( ! empty( $existing ) ) {
			self::link( $saleson_product_id, (int) $existing[0], 'relinked_existing' );
			return array( 'ok' => true, 'woo_product_id' => (int) $existing[0] );
		}

		$name = $row->saleson_name ? $row->saleson_name : $row->cache_name;
		if ( ! $name ) {
			return array( 'ok' => false, 'error' => 'SalesOn product has no name yet.' );
		}

		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'draft' );
		$product->set_catalog_visibility( 'visible' );

		// Price: prefer the real RETAIL tier rate (what a retail customer should
		// pay), falling back to the product's own sell_price. Left empty rather
		// than guessed if neither exists - the regular sync will fill it in, and
		// an empty price is an honest "not priced for retail yet" signal.
		$retail = $wpdb->get_var( $wpdb->prepare(
			"SELECT rate FROM {$wpdb->prefix}saleson_price_tiers WHERE saleson_product_id = %d AND price_list_id = %d",
			$saleson_product_id,
			Saleson_API::PRICE_LIST_RETAIL
		) );
		$price = ( null !== $retail && '' !== $retail ) ? $retail : $row->sell_price;
		if ( null !== $price && '' !== $price ) {
			$product->set_regular_price( (string) round( (float) $price, 2 ) );
		}

		// Stock management on from the start - the recurring sync keeps the
		// number current, and it's what makes an out-of-stock product show as
		// unorderable rather than silently sellable.
		$product->set_manage_stock( true );
		$product->set_stock_quantity( null !== $row->cache_stock ? (int) $row->cache_stock : 0 );

		$woo_product_id = $product->save();

		if ( ! $woo_product_id ) {
			return array( 'ok' => false, 'error' => 'WooCommerce could not create the product.' );
		}

		update_post_meta( $woo_product_id, self::META_KEY, $saleson_product_id );

		if ( ! empty( $row->category ) ) {
			self::assign_category( $woo_product_id, $row->category );
		}

		self::link( $saleson_product_id, $woo_product_id, 'saleson_auto_import' );

		return array( 'ok' => true, 'woo_product_id' => $woo_product_id );
	}

	private static function link( $saleson_product_id, $woo_product_id, $source ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'saleson_product_map',
			array(
				'woo_product_id' => $woo_product_id,
				'mapping_status' => 'matched',
				'confidence'     => 'exact',
				'source'         => $source,
				'mapped_at'      => current_time( 'mysql' ),
				'listing_status' => 'not_listed',
			),
			array( 'saleson_product_id' => $saleson_product_id ),
			array( '%d', '%s', '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mirrors Saleson_Matcher_Page::maybe_assign_category() - creates the
	 * product_cat term if it doesn't exist yet, then assigns it.
	 */
	private static function assign_category( $woo_product_id, $category_name ) {
		$category_name = trim( (string) $category_name );
		if ( '' === $category_name ) {
			return;
		}

		$term = term_exists( $category_name, 'product_cat' );
		if ( ! $term ) {
			$term = wp_insert_term( $category_name, 'product_cat' );
		}
		if ( is_wp_error( $term ) || empty( $term['term_id'] ) ) {
			return;
		}

		wp_set_object_terms( $woo_product_id, array( (int) $term['term_id'] ), 'product_cat', false );
	}
}
