<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One-time seed importer for wp_saleson_product_map, sourced from the Phase 0
 * reconciliation spreadsheet (SalesOn-WooCommerce-Product-Mapping-Review.xlsx).
 *
 * XLSX parsing: no Composer/PhpSpreadsheet dependency exists in this plugin, so
 * this reads the file "by hand" using ZipArchive + SimpleXML - an .xlsx is just a
 * zip of XML parts. We read xl/workbook.xml (sheet name -> r:id), xl/_rels/workbook.xml.rels
 * (r:id -> worksheet part), optionally xl/sharedStrings.xml (only if the workbook
 * uses shared strings - the Phase 0 file uses inline strings throughout, but shared
 * strings are supported too so this isn't a fragile single-file hack), and finally
 * the worksheet's own <sheetData> rows.
 *
 * Synthetic keys for Woo-only rows: see build_synthetic_id() docblock below - this
 * is the key design decision called out in the task and reported back to the user.
 *
 * Idempotency: a row already present in wp_saleson_product_map is never restatused
 * by a re-import (that would silently undo a human's Confirm/Reject/Create decision
 * made on the Matcher page). Only the descriptive "stash" columns (names, category,
 * stock, sku, notes) are refreshed on existing rows. Brand-new saleson_product_id
 * values are inserted in full. Running the import twice is therefore safe.
 */
class Saleson_Map_Importer {

	const SHEET_MATCHED       = 'Matched Products';
	const SHEET_UNMATCHED     = 'SalesOn Items Not On Website';
	const SHEET_ORPHAN        = 'Website Products Not In SalesOn';
	const SHEET_IGNORED       = 'Blank Placeholder Products';

	// Synthetic saleson_product_id space for Woo-only rows (orphans / placeholders).
	// wp_saleson_product_map.saleson_product_id is BIGINT UNSIGNED and is the table's
	// PRIMARY KEY, so it can never be NULL and (being unsigned) can never be negative
	// either - a plain "-woo_id" scheme, the first thing one reaches for, doesn't fit
	// the column type. Instead we offset the real WooCommerce product id into a range
	// that real SalesOn ids (observed to top out around ~7 digits in Phase 0) will
	// never reach, using a different offset per status so the id alone tells you
	// which bucket a row came from and woo_product_id = saleson_product_id - OFFSET
	// recovers the original WooCommerce id losslessly (Woo ids are unique per store,
	// so no collisions are possible within or across the two offset ranges).
	const ORPHAN_ID_OFFSET  = 900000000000; // "Website Products Not In SalesOn"
	const IGNORED_ID_OFFSET = 800000000000; // "Blank Placeholder Products"

	const SOURCE = 'spreadsheet_seed';

	/**
	 * @param string|null $file_path defaults to the Phase 0 spreadsheet shipped
	 *                                alongside the WordPress install (repo root /phase0/...).
	 *                                Override via the 'saleson_map_import_file_path' filter
	 *                                or by passing an explicit path (e.g. an uploaded file).
	 * @return array{ok:bool, error?:string, counts?:array}
	 */
	public static function import_from_spreadsheet( $file_path = null ) {
		if ( null === $file_path ) {
			$file_path = apply_filters( 'saleson_map_import_file_path', self::default_file_path() );
		}

		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
			return array(
				'ok'    => false,
				'error' => sprintf(
					/* translators: %s: file path */
					__( 'Spreadsheet not found at %s', 'saleson-woo-sync' ),
					(string) $file_path
				),
			);
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'PHP ZipArchive extension is not available; cannot read the .xlsx file.', 'saleson-woo-sync' ),
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Could not open the spreadsheet as a zip archive.', 'saleson-woo-sync' ),
			);
		}

		$counts = array(
			'matched'   => array( 'inserted' => 0, 'refreshed' => 0 ),
			'unmatched' => array( 'inserted' => 0, 'refreshed' => 0 ),
			'orphan'    => array( 'inserted' => 0, 'refreshed' => 0 ),
			'ignored'   => array( 'inserted' => 0, 'refreshed' => 0 ),
		);

		$errors = array();

		try {
			$matched = self::read_sheet( $zip, self::SHEET_MATCHED );
			foreach ( $matched as $row ) {
				$saleson_id = self::to_int( self::col( $row, 'SalesOn ID' ) );
				$woo_id     = self::to_int( self::col( $row, 'WooCommerce ID' ) );
				if ( ! $saleson_id ) {
					continue;
				}
				$result = self::upsert(
					$saleson_id,
					array(
						'woo_product_id' => $woo_id ?: null,
						'mapping_status'  => 'matched',
						'confidence'      => self::col( $row, 'Match Confidence' ),
						'source'          => self::SOURCE,
						'mapped_at'       => current_time( 'mysql' ),
						'saleson_name'    => self::col( $row, 'SalesOn Name' ),
						'woo_name'        => self::col( $row, 'WooCommerce Name' ),
					)
				);
				$counts['matched'][ $result ]++;
			}

			$unmatched = self::read_sheet( $zip, self::SHEET_UNMATCHED );
			foreach ( $unmatched as $row ) {
				$saleson_id = self::to_int( self::col( $row, 'SalesOn ID' ) );
				if ( ! $saleson_id ) {
					continue;
				}
				$result = self::upsert(
					$saleson_id,
					array(
						'woo_product_id' => null,
						'mapping_status'  => 'unmatched',
						'source'          => self::SOURCE,
						'saleson_name'    => self::col( $row, 'Name' ),
						'category'        => self::col( $row, 'Category/Group' ),
						'stock'           => self::to_int( self::col( $row, 'Stock' ) ),
					)
				);
				$counts['unmatched'][ $result ]++;
			}

			$orphans = self::read_sheet( $zip, self::SHEET_ORPHAN );
			foreach ( $orphans as $row ) {
				$woo_id = self::to_int( self::col( $row, 'WooCommerce ID' ) );
				if ( ! $woo_id ) {
					continue;
				}
				$synthetic_id = self::ORPHAN_ID_OFFSET + $woo_id;
				$result       = self::upsert(
					$synthetic_id,
					array(
						'woo_product_id' => $woo_id,
						'mapping_status'  => 'orphan',
						'source'          => self::SOURCE,
						'woo_name'        => self::col( $row, 'Name' ),
						'sku'             => self::col( $row, 'SKU' ),
						'category'        => self::col( $row, 'Categories' ),
					)
				);
				$counts['orphan'][ $result ]++;
			}

			$ignored = self::read_sheet( $zip, self::SHEET_IGNORED );
			foreach ( $ignored as $row ) {
				$woo_id = self::to_int( self::col( $row, 'WooCommerce ID' ) );
				if ( ! $woo_id ) {
					continue;
				}
				$synthetic_id = self::IGNORED_ID_OFFSET + $woo_id;
				$result       = self::upsert(
					$synthetic_id,
					array(
						'woo_product_id' => $woo_id,
						'mapping_status'  => 'ignored',
						'source'          => self::SOURCE,
						'sku'             => self::col( $row, 'SKU' ),
						'category'        => self::col( $row, 'Categories' ),
						'notes'           => self::col( $row, 'Notes' ),
					)
				);
				$counts['ignored'][ $result ]++;
			}
		} catch ( Exception $e ) {
			$errors[] = $e->getMessage();
		}

		$zip->close();

		update_option( 'saleson_map_import_last_run', array(
			'when'   => current_time( 'mysql' ),
			'counts' => $counts,
			'errors' => $errors,
		) );

		return array(
			'ok'     => empty( $errors ),
			'error'  => empty( $errors ) ? null : implode( '; ', $errors ),
			'counts' => $counts,
		);
	}

	const CURATED_SHEET = 'Item Master';

	/**
	 * Marks exactly the SalesOn ids present in the client-approved curated catalog
	 * (items-28-07.xlsx, "Item Master" sheet, column "Id") as is_curated=1, and
	 * everything else as is_curated=0. Deliberately a full reset-then-set on every
	 * run (unlike import_from_spreadsheet()'s never-touch-existing-status
	 * behaviour) - the curated list is the one thing meant to be a hard, current
	 * source of truth: if the client sends an updated curated file later, re-running
	 * this should reflect that new list exactly, not accumulate stale entries.
	 * Does NOT alter mapping_status/woo_product_id - only the is_curated flag.
	 *
	 * @return array{ok:bool, error?:string, count?:int}
	 */
	public static function import_curated_list( $file_path = null ) {
		if ( null === $file_path ) {
			$file_path = apply_filters( 'saleson_curated_import_file_path', self::default_curated_file_path() );
		}

		if ( ! is_string( $file_path ) || '' === $file_path || ! file_exists( $file_path ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Curated items file not found.', 'saleson-woo-sync' ),
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return array(
				'ok'    => false,
				'error' => __( 'Could not open the curated items file as a zip archive.', 'saleson-woo-sync' ),
			);
		}

		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';
		$count = 0;

		try {
			$rows = self::read_sheet( $zip, self::CURATED_SHEET );

			$curated_ids = array();
			foreach ( $rows as $row ) {
				$saleson_id = self::to_int( self::col( $row, 'Id' ) );
				if ( $saleson_id ) {
					$curated_ids[] = $saleson_id;
				}
			}

			// Reset first, then set - see docblock on why this is a full resync,
			// not an additive/insert-only operation like the spreadsheet importer.
			$wpdb->query( "UPDATE {$table} SET is_curated = 0" );

			foreach ( $curated_ids as $saleson_id ) {
				$existing = $wpdb->get_var(
					$wpdb->prepare( "SELECT saleson_product_id FROM {$table} WHERE saleson_product_id = %d", $saleson_id )
				);
				if ( null !== $existing ) {
					$wpdb->update( $table, array( 'is_curated' => 1 ), array( 'saleson_product_id' => $saleson_id ) );
				} else {
					// Shouldn't normally happen (the cron's ensure_product_map_row()
					// seeds every real SalesOn product it sees), but insert a minimal
					// row rather than silently drop a curated id.
					$wpdb->insert( $table, array(
						'saleson_product_id' => $saleson_id,
						'mapping_status'     => 'unmatched',
						'source'             => 'curated_seed',
						'is_curated'         => 1,
					) );
				}
				$count++;
			}
		} catch ( Exception $e ) {
			$zip->close();
			return array( 'ok' => false, 'error' => $e->getMessage() );
		}

		$zip->close();

		update_option( 'saleson_curated_import_last_run', array(
			'when'  => current_time( 'mysql' ),
			'count' => $count,
		) );

		return array( 'ok' => true, 'count' => $count );
	}

	private static function default_curated_file_path() {
		return SALESON_WOO_SYNC_DIR . 'data/items-28-07.xlsx';
	}

	private static function default_file_path() {
		// Bundled inside the plugin's own /data directory so it deploys as one unit -
		// deployment only ships this plugin folder, not the wider dev repo, so a path
		// like ABSPATH . 'phase0/...' would not exist on the live server.
		return SALESON_WOO_SYNC_DIR . 'data/SalesOn-WooCommerce-Product-Mapping-Review.xlsx';
	}

	// --- DB -------------------------------------------------------------------

	/**
	 * Insert-or-refresh a single mapping row, keyed by saleson_product_id.
	 *
	 * - Row absent: insert in full (status, woo id, source, stash columns).
	 * - Row present: only refresh the descriptive stash columns (name/category/
	 *   stock/sku/notes). mapping_status, woo_product_id, source, confidence and
	 *   mapped_at are left untouched so a re-import can never clobber a human
	 *   decision made on the Matcher page (Confirm/Reject/Create as product).
	 *
	 * @return string 'inserted'|'refreshed'
	 */
	private static function upsert( $saleson_id, array $fields ) {
		global $wpdb;
		$table = $wpdb->prefix . 'saleson_product_map';

		$existing_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT saleson_product_id FROM {$table} WHERE saleson_product_id = %d", $saleson_id )
		);

		$stash_columns = array( 'saleson_name', 'woo_name', 'category', 'sku', 'stock', 'notes' );

		if ( null !== $existing_id ) {
			$update = array();
			foreach ( $stash_columns as $col ) {
				if ( array_key_exists( $col, $fields ) && '' !== $fields[ $col ] && null !== $fields[ $col ] ) {
					$update[ $col ] = $fields[ $col ];
				}
			}
			if ( ! empty( $update ) ) {
				$wpdb->update( $table, $update, array( 'saleson_product_id' => $saleson_id ) );
			}
			return 'refreshed';
		}

		$data = array_merge( array( 'saleson_product_id' => $saleson_id ), $fields );
		// Normalize empty strings to null for numeric/optional columns.
		foreach ( $data as $k => $v ) {
			if ( '' === $v ) {
				$data[ $k ] = null;
			}
		}
		$wpdb->insert( $table, $data );

		return 'inserted';
	}

	private static function to_int( $value ) {
		if ( null === $value || '' === $value ) {
			return 0;
		}
		return (int) round( (float) $value );
	}

	private static function col( array $row, $header ) {
		return isset( $row[ $header ] ) ? trim( (string) $row[ $header ] ) : '';
	}

	// --- Minimal XLSX reader (ZipArchive + SimpleXML) --------------------------

	/**
	 * @return array<int, array<string,string>> rows keyed by header-row column name
	 */
	private static function read_sheet( ZipArchive $zip, $sheet_name ) {
		$target = self::locate_sheet_part( $zip, $sheet_name );
		if ( ! $target ) {
			throw new Exception( sprintf( 'Sheet "%s" not found in workbook', $sheet_name ) );
		}

		$shared_strings = self::read_shared_strings( $zip );

		$xml_contents = $zip->getFromName( $target );
		if ( false === $xml_contents ) {
			throw new Exception( sprintf( 'Could not read worksheet part "%s"', $target ) );
		}

		$xml = simplexml_load_string( $xml_contents );
		if ( false === $xml ) {
			throw new Exception( sprintf( 'Could not parse worksheet XML for "%s"', $sheet_name ) );
		}

		$rows = array();
		foreach ( $xml->sheetData->row as $row_xml ) {
			$cells = array();
			foreach ( $row_xml->c as $cell_xml ) {
				$ref       = (string) $cell_xml['r'];
				$col_index = self::col_letter_to_index( preg_replace( '/[0-9]+/', '', $ref ) );
				$cells[ $col_index ] = self::cell_value( $cell_xml, $shared_strings );
			}
			$rows[] = $cells;
		}

		if ( empty( $rows ) ) {
			return array();
		}

		// First row is the header; map every subsequent row's cells to header names.
		$header_cells = array_shift( $rows );
		$headers      = array();
		foreach ( $header_cells as $idx => $value ) {
			$headers[ $idx ] = trim( $value );
		}

		$out = array();
		foreach ( $rows as $cells ) {
			$named = array();
			foreach ( $headers as $idx => $name ) {
				if ( '' === $name ) {
					continue;
				}
				$named[ $name ] = isset( $cells[ $idx ] ) ? $cells[ $idx ] : '';
			}
			// Skip fully-blank rows.
			if ( count( array_filter( $named, function ( $v ) { return '' !== $v; } ) ) === 0 ) {
				continue;
			}
			$out[] = $named;
		}

		return $out;
	}

	private static function cell_value( $cell_xml, array $shared_strings ) {
		$type = isset( $cell_xml['t'] ) ? (string) $cell_xml['t'] : 'n';

		switch ( $type ) {
			case 's': // shared string index
				$idx = isset( $cell_xml->v ) ? (int) $cell_xml->v : -1;
				return isset( $shared_strings[ $idx ] ) ? $shared_strings[ $idx ] : '';

			case 'inlineStr':
				if ( isset( $cell_xml->is ) ) {
					return self::concat_rich_text( $cell_xml->is );
				}
				return '';

			case 'str': // formula cached string result
			case 'b':   // boolean
			case 'n':   // numeric
			default:
				return isset( $cell_xml->v ) ? (string) $cell_xml->v : '';
		}
	}

	private static function concat_rich_text( $is_node ) {
		if ( isset( $is_node->t ) ) {
			return (string) $is_node->t;
		}
		$text = '';
		if ( isset( $is_node->r ) ) {
			foreach ( $is_node->r as $run ) {
				$text .= isset( $run->t ) ? (string) $run->t : '';
			}
		}
		return $text;
	}

	private static function col_letter_to_index( $letters ) {
		$letters = strtoupper( $letters );
		$index   = 0;
		for ( $i = 0; $i < strlen( $letters ); $i++ ) {
			$index = $index * 26 + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
		}
		return $index - 1; // 0-based
	}

	private static function read_shared_strings( ZipArchive $zip ) {
		$contents = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false === $contents ) {
			return array(); // fine - this workbook may use inline strings exclusively.
		}
		$xml = simplexml_load_string( $contents );
		if ( false === $xml ) {
			return array();
		}
		$strings = array();
		foreach ( $xml->si as $si ) {
			$strings[] = self::concat_rich_text( $si );
		}
		return $strings;
	}

	/**
	 * Resolves a worksheet's zip part path (e.g. "xl/worksheets/sheet2.xml") from its
	 * human-readable name, via xl/workbook.xml (name -> r:id) and
	 * xl/_rels/workbook.xml.rels (r:id -> target part).
	 */
	private static function locate_sheet_part( ZipArchive $zip, $sheet_name ) {
		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		if ( false === $workbook_xml ) {
			throw new Exception( 'xl/workbook.xml missing from spreadsheet' );
		}
		$workbook = simplexml_load_string( $workbook_xml );
		if ( false === $workbook ) {
			throw new Exception( 'Could not parse xl/workbook.xml' );
		}

		$rid = null;
		foreach ( $workbook->sheets->sheet as $sheet ) {
			if ( (string) $sheet['name'] === $sheet_name ) {
				$attrs = $sheet->attributes( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships' );
				$rid   = (string) $attrs['id'];
				break;
			}
		}
		if ( ! $rid ) {
			return null;
		}

		$rels_xml = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $rels_xml ) {
			throw new Exception( 'xl/_rels/workbook.xml.rels missing from spreadsheet' );
		}
		$rels = simplexml_load_string( $rels_xml );
		if ( false === $rels ) {
			throw new Exception( 'Could not parse xl/_rels/workbook.xml.rels' );
		}

		foreach ( $rels->Relationship as $rel ) {
			if ( (string) $rel['Id'] === $rid ) {
				$target = ltrim( (string) $rel['Target'], '/' );
				// Targets are usually relative to xl/ (e.g. "worksheets/sheet2.xml"),
				// but some writers emit it already xl/-prefixed - don't double it.
				$candidate = ( 0 === strpos( $target, 'xl/' ) ) ? $target : 'xl/' . $target;
				if ( false !== $zip->locateName( $candidate ) ) {
					return $candidate;
				}
				// Fall back to trying the other form, in case a writer did something unusual.
				$alt = ( 0 === strpos( $target, 'xl/' ) ) ? substr( $target, 3 ) : 'xl/' . $target;
				if ( false !== $zip->locateName( $alt ) ) {
					return $alt;
				}
				throw new Exception( 'Could not locate worksheet part for Target "' . $target . '" in the archive' );
			}
		}

		return null;
	}
}
