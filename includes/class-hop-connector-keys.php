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
		$key = array(
			'key_id'  => bin2hex( random_bytes( 8 ) ),
			'secret'  => bin2hex( random_bytes( 32 ) ),
			'expires' => time() + self::PENDING_TTL,
		);

		delete_option( self::OPTION_PENDING );
		add_option( self::OPTION_PENDING, $key, '', false );

		return 'hop1_' . $key['key_id'] . '_' . $key['secret'];
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
	 * @return void
	 */
	public static function promote_pending() {
		$pending = self::pending();

		if ( ! $pending ) {
			return;
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
