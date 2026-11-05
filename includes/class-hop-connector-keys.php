<?php
/**
 * Storage of the platform's key and of the pending pairing token.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Key lifecycle.
 *
 * The site ends up holding the platform's Ed25519 public key only: reading
 * its database, or a copy of it, gives nothing that can sign a request.
 * A pairing token from the admin screen carries a one-time secret, which the
 * platform replaces with its public key when it confirms the token. Sites
 * paired before version 0.8.0 hold a shared secret until the platform moves
 * them to its public key (offer_public_key(), then drop_secret()).
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
	 * "hop1" carries a one-time secret, to be replaced at the handshake.
	 * "hop2" carries the platform's public key: it is no secret, so it may
	 * show in a command line or a process list.
	 *
	 * @param  string $token  Token as shown by the admin screen or sent by the platform.
	 * @return array{type:string,key_id:string,secret?:string,public_key?:string}|null
	 */
	public static function parse_token( $token ) {
		if ( ! preg_match( '/^hop([12])_([a-f0-9]{16})_([a-f0-9]{64})$/', trim( (string) $token ), $matches ) ) {
			return null;
		}

		if ( '2' === $matches[1] ) {
			return array(
				'type'       => 'public_key',
				'key_id'     => $matches[2],
				'public_key' => $matches[3],
			);
		}

		return array(
			'type'   => 'secret',
			'key_id' => $matches[2],
			'secret' => $matches[3],
		);
	}

	/**
	 * Active key, or null when the site is not paired.
	 *
	 * @return array{key_id:string,secret?:string,public_key?:string,paired_at:int}|null
	 */
	public static function active() {
		$key = get_option( self::OPTION_ACTIVE );

		if ( ! is_array( $key ) || empty( $key['key_id'] ) || ( empty( $key['secret'] ) && empty( $key['public_key'] ) ) ) {
			return null;
		}

		return $key;
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
	 * @return array{secret:string,public_key:string,pending:bool}|null Empty strings for what the key does not hold.
	 */
	public static function find( $key_id ) {
		$active = self::active();

		if ( $active && hash_equals( (string) $active['key_id'], $key_id ) ) {
			return array(
				'secret'     => isset( $active['secret'] ) ? (string) $active['secret'] : '',
				'public_key' => isset( $active['public_key'] ) ? (string) $active['public_key'] : '',
				'pending'    => false,
			);
		}

		$pending = self::pending();

		if ( $pending && hash_equals( (string) $pending['key_id'], $key_id ) ) {
			return array(
				'secret'     => (string) $pending['secret'],
				'public_key' => '',
				'pending'    => true,
			);
		}

		return null;
	}

	/**
	 * Promote the pending key to active key, with the replacement the platform
	 * sent: its public key, or a new secret from a platform older than the
	 * connector. Either way, the secret that travelled through a copy-paste or
	 * a command line stops being valid.
	 *
	 * @param  array{public_key?:mixed,secret?:mixed} $replacement  Values received.
	 * @return string|null What the site now holds: public_key or secret; null when nothing was promoted.
	 */
	public static function promote_pending( array $replacement ) {
		$pending = self::pending();

		if ( ! $pending ) {
			return null;
		}

		foreach ( array( 'public_key', 'secret' ) as $kind ) {
			if ( isset( $replacement[ $kind ] ) && self::is_key( $replacement[ $kind ] ) ) {
				self::activate( (string) $pending['key_id'], array( $kind => $replacement[ $kind ] ) );

				return $kind;
			}
		}

		return null;
	}

	/**
	 * Make a key the only valid one.
	 *
	 * @param  string                $key_id    Key identifier.
	 * @param  array<string, string> $material  public_key or secret.
	 * @return void
	 */
	public static function activate( $key_id, array $material ) {
		delete_option( self::OPTION_ACTIVE );
		add_option( self::OPTION_ACTIVE, array_merge( array( 'key_id' => $key_id ), $material, array( 'paired_at' => time() ) ), '', false );
		delete_option( self::OPTION_PENDING );
	}

	/**
	 * First step of the move from a shared secret to the platform's public
	 * key. The secret keeps working until the platform proves, by a request
	 * signed with its private key, that it holds the matching key: an answer
	 * lost on the way cannot cut the site off.
	 *
	 * @param  string $public_key  Platform public key, 64 hexadecimal characters.
	 * @return bool Whether the key was recorded.
	 */
	public static function offer_public_key( $public_key ) {
		$active = self::active();

		if ( ! $active || empty( $active['secret'] ) || ! self::is_key( $public_key ) ) {
			return false;
		}

		$active['public_key'] = $public_key;

		return update_option( self::OPTION_ACTIVE, $active, false ) || self::active() === $active;
	}

	/**
	 * Last step of the move: forget the shared secret.
	 *
	 * @return bool Whether the site now holds the public key alone.
	 */
	public static function drop_secret() {
		$active = self::active();

		if ( ! $active || empty( $active['public_key'] ) ) {
			return false;
		}

		if ( isset( $active['secret'] ) ) {
			unset( $active['secret'] );
			update_option( self::OPTION_ACTIVE, $active, false );
		}

		return 'ed25519' === self::signing();
	}

	/**
	 * How the platform signs its requests to this site.
	 *
	 * @return string|null ed25519, hmac, or ed25519_pending while the move from the secret is under way; null when not paired.
	 */
	public static function signing() {
		$active = self::active();

		if ( ! $active ) {
			return null;
		}

		if ( empty( $active['public_key'] ) ) {
			return 'hmac';
		}

		return empty( $active['secret'] ) ? 'ed25519' : 'ed25519_pending';
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

	/**
	 * Shape of a secret or of a public key: 32 bytes, in hexadecimal.
	 *
	 * @param  mixed $value  Value received.
	 * @return bool
	 */
	private static function is_key( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^[a-f0-9]{64}$/', $value );
	}
}
