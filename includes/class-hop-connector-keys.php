<?php
/**
 * Storage of the signing key and of the pending pairing token.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Key lifecycle: a pending key is created from the admin screen, then promoted
 * to the active key by the first valid signed handshake.
 */
class Hop_Connector_Keys {

	const OPTION_ACTIVE = 'hop_connector_key';

	const OPTION_PENDING = 'hop_connector_pending_key';

	const PENDING_TTL = 900;

	/**
	 * Create a pending key and return the pairing token to paste in the platform.
	 *
	 * @return string
	 */
	public static function generate_pending() {
		$key_id = bin2hex( random_bytes( 8 ) );
		$secret = bin2hex( random_bytes( 32 ) );

		self::store_pending( $key_id, $secret );

		return 'hop1_' . $key_id . '_' . $secret;
	}

	/**
	 * Store a pending key chosen elsewhere: by the platform, through WP-CLI.
	 *
	 * @param  string $key_id  Key identifier, 16 hexadecimal characters.
	 * @param  string $secret  Secret, 64 hexadecimal characters.
	 * @return void
	 */
	public static function store_pending( $key_id, $secret ) {
		delete_option( self::OPTION_PENDING );
		add_option(
			self::OPTION_PENDING,
			array(
				'key_id'  => $key_id,
				'secret'  => $secret,
				'expires' => time() + self::PENDING_TTL,
			),
			'',
			false
		);
	}

	/**
	 * Parse a pairing token.
	 *
	 * @param  string $token  Token as shown by the admin screen or sent by the platform.
	 * @return array{key_id:string,secret:string}|null
	 */
	public static function parse_token( $token ) {
		if ( ! preg_match( '/^hop1_([a-f0-9]{16})_([a-f0-9]{64})$/', trim( (string) $token ), $matches ) ) {
			return null;
		}

		return array(
			'key_id' => $matches[1],
			'secret' => $matches[2],
		);
	}

	/**
	 * Active key, or null when the site is not paired.
	 *
	 * @return array{key_id:string,secret:string,paired_at:int}|null
	 */
	public static function active() {
		$key = get_option( self::OPTION_ACTIVE );

		return is_array( $key ) && ! empty( $key['key_id'] ) && ! empty( $key['secret'] ) ? $key : null;
	}

	/**
	 * Pending key if it has not expired.
	 *
	 * @return array{key_id:string,secret:string,expires:int}|null
	 */
	public static function pending() {
		$key = get_option( self::OPTION_PENDING );

		if ( ! is_array( $key ) || empty( $key['key_id'] ) || empty( $key['secret'] ) ) {
			return null;
		}

		if ( (int) $key['expires'] < time() ) {
			delete_option( self::OPTION_PENDING );

			return null;
		}

		return $key;
	}

	/**
	 * Find the key (active or pending) matching an identifier.
	 *
	 * @param  string $key_id  Key identifier received.
	 * @return array{secret:string,pending:bool}|null
	 */
	public static function find( $key_id ) {
		$active = self::active();

		if ( $active && hash_equals( $active['key_id'], $key_id ) ) {
			return array(
				'secret'  => $active['secret'],
				'pending' => false,
			);
		}

		$pending = self::pending();

		if ( $pending && hash_equals( $pending['key_id'], $key_id ) ) {
			return array(
				'secret'  => $pending['secret'],
				'pending' => true,
			);
		}

		return null;
	}

	/**
	 * Promote the pending key to active key.
	 *
	 * The platform may send a new secret with the handshake, so the one that
	 * travelled through a copy-paste or a command history stops being valid.
	 *
	 * @param  string|null $new_secret  Replacement secret, 64 hexadecimal characters.
	 * @return bool Whether the secret was replaced.
	 */
	public static function promote_pending( $new_secret = null ) {
		$pending = self::pending();

		if ( ! $pending ) {
			return false;
		}

		$rotated = is_string( $new_secret ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $new_secret );

		if ( $rotated ) {
			$pending['secret'] = $new_secret;
		}

		delete_option( self::OPTION_ACTIVE );
		add_option(
			self::OPTION_ACTIVE,
			array(
				'key_id'    => $pending['key_id'],
				'secret'    => $pending['secret'],
				'paired_at' => time(),
			),
			'',
			false
		);
		delete_option( self::OPTION_PENDING );

		return $rotated;
	}

	/**
	 * Remove every key: the platform can no longer reach this site.
	 *
	 * @return void
	 */
	public static function revoke() {
		delete_option( self::OPTION_ACTIVE );
		delete_option( self::OPTION_PENDING );
	}
}
