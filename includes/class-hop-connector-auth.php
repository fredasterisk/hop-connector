<?php
/**
 * Authentication of signed requests coming from the platform.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Permission callbacks for the REST routes.
 */
class Hop_Connector_Auth {

	const OPTION_ALLOWED_IPS = 'hop_connector_allowed_ips';

	const NONCE_PREFIX = 'hop_connector_nonce_';

	const NONCE_TTL = 300;

	/**
	 * Algorithm that authenticated the current request: ed25519 or hmac.
	 *
	 * @var string|null
	 */
	private static $algorithm = null;

	/**
	 * Permission callback for routes that need the active key.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return true|WP_Error
	 */
	public static function check( WP_REST_Request $request ) {
		return self::authenticate( $request, false );
	}

	/**
	 * Permission callback for the handshake, which also accepts the pending key.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @return true|WP_Error
	 */
	public static function check_handshake( WP_REST_Request $request ) {
		return self::authenticate( $request, true );
	}

	/**
	 * Verify IP allowlist, freshness, signature, then replay protection.
	 *
	 * Key and signature failures all return the same public error, so a caller
	 * cannot tell which one rejected the request; the precise reason goes to the
	 * debug log. Clock skew and IP refusal are checked before any key material
	 * and are reported as such, because the platform needs them for diagnostics.
	 *
	 * @param  WP_REST_Request $request  Request.
	 * @param  bool            $allow_pending  Whether the pending key is accepted.
	 * @return true|WP_Error
	 */
	private static function authenticate( WP_REST_Request $request, $allow_pending ) {
		// Only the signed body may carry parameters: a query string, or a body
		// read as a form instead of JSON, would escape the signature.
		$unsigned = array_diff( array_keys( $request->get_query_params() ), array( 'rest_route' ) );

		if ( array() !== $unsigned || ( '' !== (string) $request->get_body() && ! $request->is_json_content_type() ) ) {
			return self::deny( 'hop_unsigned_parameters' );
		}

		return self::verify(
			$request->get_method(),
			$request->get_route(),
			(string) $request->get_header( 'x_hop_key_id' ),
			(string) $request->get_header( 'x_hop_timestamp' ),
			(string) $request->get_header( 'x_hop_nonce' ),
			(string) $request->get_header( 'x_hop_signature' ),
			(string) $request->get_body(),
			$allow_pending
		);
	}

	/**
	 * Same checks without a WP_REST_Request, for the rescue endpoint that runs
	 * before the REST API exists.
	 *
	 * @param  string $method         HTTP method.
	 * @param  string $route          Signed route.
	 * @param  string $key_id         Key identifier header.
	 * @param  string $timestamp      Timestamp header.
	 * @param  string $nonce          Nonce header.
	 * @param  string $signature      Signature header.
	 * @param  string $body           Raw request body.
	 * @param  bool   $allow_pending  Whether the pending key is accepted.
	 * @return true|WP_Error
	 */
	public static function verify( $method, $route, $key_id, $timestamp, $nonce, $signature, $body, $allow_pending = false ) {
		if ( ! self::ip_is_allowed() ) {
			return self::deny( 'hop_ip_denied' );
		}

		// On a network, only the main site answers: it is the one Hop pairs
		// with, and the connector acts on the whole network from there.
		if ( is_multisite() && ! is_main_site() ) {
			return self::deny( 'hop_not_main_site' );
		}

		if ( '' === $key_id || ! preg_match( '/^[A-Za-z0-9_-]{16,64}$/', $nonce ) ) {
			return self::deny( 'hop_bad_signature' );
		}

		if ( ! Hop_Connector_Signature::is_fresh( $timestamp, time() ) ) {
			return self::deny( 'hop_clock_skew' );
		}

		$key = Hop_Connector_Keys::find( $key_id );

		if ( ! $key || ( $key['pending'] && ! $allow_pending ) ) {
			return self::deny( 'hop_bad_signature' );
		}

		// A key holding the public key alone accepts Ed25519 only: no request
		// can fall back to a secret the site no longer has.
		if ( '' !== $key['public_key'] && Hop_Connector_Signature::verify_ed25519( $signature, $key['public_key'], $method, $route, $timestamp, $nonce, $key_id, $body ) ) {
			$algorithm = 'ed25519';
		} elseif ( '' !== $key['secret'] && Hop_Connector_Signature::verify( $signature, $key['secret'], $method, $route, $timestamp, $nonce, $key_id, $body ) ) {
			$algorithm = 'hmac';
		} else {
			return self::deny( 'hop_bad_signature' );
		}

		if ( ! self::remember_nonce( $key_id, $nonce ) ) {
			return self::deny( 'hop_replay' );
		}

		self::$algorithm = $algorithm;

		return true;
	}

	/**
	 * Algorithm that authenticated the current request.
	 *
	 * @return string|null ed25519 or hmac; null before a request passed verify().
	 */
	public static function algorithm() {
		return self::$algorithm;
	}

	/**
	 * Record a nonce, or tell it was already used. The database decides in a
	 * single statement: two copies of a request sent at once cannot both pass,
	 * as they could between the read and the write of add_option().
	 *
	 * @param  string $key_id  Key identifier.
	 * @param  string $nonce   Nonce of the request.
	 * @return bool Whether the nonce is new.
	 */
	private static function remember_nonce( $key_id, $nonce ) {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic insert of our own row, never read through the options API.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				self::NONCE_PREFIX . hash( 'sha256', $key_id . '|' . $nonce ),
				(string) ( time() + self::NONCE_TTL )
			)
		);

		return 1 === $inserted;
	}

	/**
	 * Uniform public error.
	 *
	 * @param  string $reason  Internal reason, logged only.
	 * @return WP_Error
	 */
	private static function deny( $reason ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Opt-in diagnostic, never exposed to the caller.
			error_log( 'Hop Connector: request rejected (' . $reason . ').' );
		}

		$code = in_array( $reason, array( 'hop_clock_skew', 'hop_ip_denied' ), true ) ? $reason : 'hop_unauthorized';

		return new WP_Error(
			$code,
			__( 'Requête non autorisée.', 'hop-connector' ),
			array(
				'status'      => 401,
				'server_time' => time(),
			)
		);
	}

	/**
	 * Optional IP allowlist. Empty list means no restriction.
	 *
	 * @return bool
	 */
	private static function ip_is_allowed() {
		$allowed = array_filter( array_map( 'trim', explode( "\n", (string) get_option( self::OPTION_ALLOWED_IPS, '' ) ) ) );

		if ( empty( $allowed ) ) {
			return true;
		}

		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client IP compared to the allowlist.
		 *
		 * Only REMOTE_ADDR is trusted by default. Sites behind a reverse proxy
		 * that does not rewrite it can return the real address here.
		 *
		 * @param  string  $remote  Client IP address.
		 */
		$remote = (string) apply_filters( 'hop_connector_client_ip', $remote );

		return in_array( $remote, $allowed, true );
	}

	/**
	 * Delete expired nonces (hourly cron).
	 *
	 * @return void
	 */
	public static function cleanup_nonces() {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off maintenance query on our own rows.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d LIMIT 5000",
				$wpdb->esc_like( self::NONCE_PREFIX ) . '%',
				time()
			)
		);

		foreach ( $names as $name ) {
			delete_option( $name );
		}
	}
}
