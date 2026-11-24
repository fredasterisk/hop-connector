<?php
/**
 * Storage of the platform's key and of the pending pairing token.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * Key lifecycle.
 *
 * The site ends up holding the platform's Ed25519 public key only: reading
 * its database, or a copy of it, gives nothing that can sign a request.
 * A pairing token from the admin screen carries a one-time secret, which the
 * platform replaces with its public key when it confirms the token.
 *
 * Since version 0.15.0, a shared secret never makes an active key: a site
 * still paired that way (before 0.8.0, and never moved since) shows as not
 * paired, and is paired again.
 */
class Fred_Espace_Client_Keys {

	const OPTION_ACTIVE = 'fred_espace_client_key';

	const OPTION_PENDING = 'fred_espace_client_pending_key';

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
	 * Active key, or null when the site is not paired. A key left from a
	 * pairing by shared secret counts as none.
	 *
	 * @return array{key_id:string,public_key:string,paired_at:int}|null
	 */
	public static function active() {
		$key = get_option( self::OPTION_ACTIVE );

		if ( ! is_array( $key ) || empty( $key['key_id'] ) || ! self::is_key( $key['public_key'] ?? null ) ) {
			return null;
		}

		return array(
			'key_id'     => (string) $key['key_id'],
			'public_key' => (string) $key['public_key'],
			'paired_at'  => (int) ( $key['paired_at'] ?? 0 ),
		);
	}

	/**
	 * Whether the site still holds a key paired by shared secret, which no
	 * request can use any more.
	 *
	 * @return bool
	 */
	public static function has_legacy_secret() {
		$key = get_option( self::OPTION_ACTIVE );

		return is_array( $key ) && ! empty( $key['secret'] ) && null === self::active();
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
				'secret'     => '',
				'public_key' => $active['public_key'],
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
	 * Promote the pending key to active key, replaced by the platform's public
	 * key: the secret that travelled through a copy-paste or a command line
	 * stops being valid. A new secret, as platforms older than 0.8.0 sent, is
	 * refused.
	 *
	 * @param  mixed $public_key  Public key received.
	 * @return bool Whether the pending key was promoted.
	 */
	public static function promote_pending( $public_key ) {
		$pending = self::pending();

		if ( ! $pending || ! self::is_key( $public_key ) ) {
			return false;
		}

		self::activate( (string) $pending['key_id'], (string) $public_key );

		return true;
	}

	/**
	 * Make a key the only valid one. When it replaces another, the journal
	 * keeps it and the site's administrator is told: whoever takes the site
	 * from the platform cannot do it unseen.
	 *
	 * @param  string $key_id      Key identifier.
	 * @param  string $public_key  Platform public key, 64 hexadecimal characters.
	 * @param  string $how         How the key came: handshake or cli.
	 * @return void
	 */
	public static function activate( $key_id, $public_key, $how = 'handshake' ) {
		$previous = self::active();

		delete_option( self::OPTION_ACTIVE );
		add_option(
			self::OPTION_ACTIVE,
			array(
				'key_id'     => $key_id,
				'public_key' => $public_key,
				'paired_at'  => time(),
			),
			'',
			false
		);
		delete_option( self::OPTION_PENDING );

		$replaced = $previous && ! hash_equals( $previous['key_id'], (string) $key_id );

		Fred_Espace_Client_Log::record(
			$replaced ? 'key_replaced' : 'paired',
			array(
				'key_id' => $key_id,
				'note'   => $replaced ? $how . ', ' . __( 'clé précédente', 'fred-espace-client' ) . ' ' . $previous['key_id'] : $how,
			)
		);

		if ( $replaced ) {
			Fred_Espace_Client_Log::notify(
				__( 'La clé du connecteur a été remplacée', 'fred-espace-client' ),
				sprintf(
					/* translators: 1: previous key identifier, 2: new key identifier. */
					__( "La clé qui permet à la plateforme d’agir sur ce site vient d’être remplacée (clé %1\$s remplacée par %2\$s). L’ancienne ne fonctionne plus.\n\nSi ce changement n’était pas prévu : déconnecter le site dans Réglages > Espace client, puis prévenir l’agence.", 'fred-espace-client' ),
					$previous['key_id'],
					$key_id
				)
			);
		}
	}

	/**
	 * How the platform signs its requests to this site.
	 *
	 * @return string|null ed25519; null when not paired.
	 */
	public static function signing() {
		return self::active() ? 'ed25519' : null;
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
