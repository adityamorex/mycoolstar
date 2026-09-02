<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Thin HTTP client for the SalesOn API. All company-scoped calls carry
 * company_id automatically. Retries once on 5xx except where the caller
 * knows a 500 is expected (e.g. probing a price-list id that may not exist).
 */
class Saleson_API {

	const BASE_URL    = 'https://app.saleson.co.in/api/v1/';
	const WAREHOUSE_ID = 14229; // HB Akeda Dungar - the only real warehouse, confirmed Phase 0.

	const PRICE_LIST_DISTRIBUTORS   = 1470;
	const PRICE_LIST_RETAIL         = 1471;
	const PRICE_LIST_DEALER         = 1479;
	const PRICE_LIST_SUPERMART      = 1480;

	private function token() {
		return get_option( 'saleson_api_token', '' );
	}

	private function company_id() {
		return get_option( 'saleson_company_id', '5249' );
	}

	private function headers( $extra = array() ) {
		return array_merge( array(
			'Authorization' => 'Bearer ' . $this->token(),
		), $extra );
	}

	/**
	 * @param string $method GET|POST|DELETE
	 * @param string $path   relative to BASE_URL, no leading slash
	 * @param array  $params query params, company_id added automatically
	 * @param array  $body   JSON body, or null
	 * @param bool   $retry_on_5xx  set false when a 500 is an expected/meaningful outcome (e.g. probing an id)
	 */
	public function request( $method, $path, $params = array(), $body = null, $retry_on_5xx = true ) {
		$params = array_merge( array( 'company_id' => $this->company_id() ), $params );
		$url    = self::BASE_URL . $path . '?' . http_build_query( $params );

		$args = array(
			'method'  => $method,
			'headers' => $this->headers( $body !== null ? array( 'Content-Type' => 'application/json' ) : array() ),
			// Reduced from 60 (2026-09-02): on a 5xx, maybe_retry() below
			// retries the SAME request with this SAME timeout again, so one
			// failing call could take up to ~121s (60 + 1s sleep + 60) before
			// this plugin even got to decide what to do about it - more than
			// enough on its own to exceed a shared-hosting PHP execution
			// limit and leave Saleson_Stock_Sync::run()'s lock stuck. 15s is
			// still generous for calls that are actually succeeding.
			'timeout' => 15,
		);
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry( $response, $method, $url, $args, $retry_on_5xx );

		return $this->to_result( $response );
	}

	/**
	 * Multipart form-data request - only the "Add Product" endpoint needs this,
	 * confirmed the rest of the API is JSON.
	 *
	 * @param array $fields  flat key => value fields (warehouses[] etc. passed as an array of JSON strings)
	 */
	public function request_multipart( $method, $path, $params, $fields ) {
		$params = array_merge( array( 'company_id' => $this->company_id() ), $params );
		$url    = self::BASE_URL . $path . '?' . http_build_query( $params );

		$boundary = wp_generate_password( 24, false );
		$body     = '';
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $v ) {
					$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}[]\"\r\n\r\n{$v}\r\n";
				}
			} else {
				$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$key}\"\r\n\r\n{$value}\r\n";
			}
		}
		$body .= "--{$boundary}--\r\n";

		$args = array(
			'method'  => $method,
			'headers' => $this->headers( array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ) ),
			'body'    => $body,
			// Reduced from 60 (2026-09-02): on a 5xx, maybe_retry() below
			// retries the SAME request with this SAME timeout again, so one
			// failing call could take up to ~121s (60 + 1s sleep + 60) before
			// this plugin even got to decide what to do about it - more than
			// enough on its own to exceed a shared-hosting PHP execution
			// limit and leave Saleson_Stock_Sync::run()'s lock stuck. 15s is
			// still generous for calls that are actually succeeding.
			'timeout' => 15,
		);

		$response = wp_remote_request( $url, $args );
		$response = $this->maybe_retry( $response, $method, $url, $args, true );

		return $this->to_result( $response );
	}

	private function maybe_retry( $response, $method, $url, $args, $retry_on_5xx ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $retry_on_5xx && $code >= 500 ) {
			sleep( 1 );
			$response = wp_remote_request( $url, $args );
		}
		return $response;
	}

	private function to_result( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'status' => 0,
				'error'  => $response->get_error_message(),
				'data'   => null,
			);
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		return array(
			'ok'     => $code >= 200 && $code < 300,
			'status' => $code,
			'error'  => $code >= 300 ? $body : null,
			'data'   => $json,
		);
	}

	// --- Convenience wrappers -------------------------------------------------

	public function get( $path, $params = array() ) {
		return $this->request( 'GET', $path, $params, null );
	}

	public function post( $path, $body = array(), $params = array() ) {
		return $this->request( 'POST', $path, $params, $body );
	}

	/**
	 * SalesOn's PATCH-style updates go over POST with a `_method: PATCH` body field.
	 * Only call this on an id you are certain exists - PATCHing a non-existent
	 * price-list id crashes with a 500 rather than a clean error (confirmed Phase 0).
	 */
	public function patch_via_post( $path, $body = array(), $params = array() ) {
		$body['_method'] = 'PATCH';
		return $this->request( 'POST', $path, $params, $body );
	}

	public function delete( $path, $params = array() ) {
		return $this->request( 'DELETE', $path, $params, null );
	}
}
