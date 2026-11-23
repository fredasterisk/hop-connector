<?php
/**
 * One-click login: single-use token valid for a few seconds.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues and consumes magic login tokens.
 */
class Hop_Connector_Login {

	const OPTION_PREFIX = 'hop_connector_login_';

	const TTL = 45;

	const QUERY_VAR = 'hop_login';

	/**
	 * Marks the accounts the connector created for the agency.
	 */
	const AGENCY_META = 'hop_connector_agency_account';

	/**
	 * Login links issued in the last hour, outside the login_ prefix that the
	 * hourly cleanup sweeps.
	 */
	const OPTION_RATE = 'hop_connector_rate_login';

	const MAX_PER_HOUR = 10;

	/**
	 * Create a login URL.
	 *
	 * The URL opens the account of the agency member who asked (created the
	 * first time), never the client's own account: what the agency does in
	 * wp-admin shows under its own name, and the client's two-factor login
	 * stays theirs. Ten links an hour at most: more is no one at work.
	 *
	 * @param  string $email  Email address of the agency member, verified by the platform.
	 * @param  string $name   Name shown for an account created now.
	 * @return array{url:string,user:array{login:string,created:bool}}|WP_Error
	 */
	public static function issue( $email, $name = '' ) {
		if ( '' === $email ) {
			return new WP_Error( 'hop_email_required', __( 'La connexion demande l’adresse courriel du membre de l’agence.', 'hop-connector' ), array( 'status' => 400 ) );
		}

		if ( ! self::take_rate() ) {
			return new WP_Error( 'hop_login_rate', __( 'Trop de connexions demandées dans la dernière heure. Réessayer plus tard.', 'hop-connector' ), array( 'status' => 429 ) );
		}

		$account = self::agency_account( $email, $name );

		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$user    = $account['user'];
		$created = $account['created'];

		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return new WP_Error( 'hop_no_user', __( 'Aucun compte administrateur disponible.', 'hop-connector' ), array( 'status' => 404 ) );
		}

		$token = bin2hex( random_bytes( 32 ) );

		add_option(
			self::OPTION_PREFIX . hash( 'sha256', $token ),
			array(
				'user_id' => $user->ID,
				'expires' => time() + self::TTL,
			),
			'',
			false
		);

		return array(
			'url'  => add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) ),
			'user' => array(
				'login'   => $user->user_login,
				'created' => $created,
			),
		);
	}

	/**
	 * The administrator account of an agency member, found by email address,
	 * or created as an administrator if the site has none with that address.
	 *
	 * An existing account that is not administrator is refused rather than
	 * promoted: on a site open to registration, anyone could have created it.
	 *
	 * @param  string $email  Email address.
	 * @param  string $name   Display name for a new account.
	 * @return array{user:WP_User,created:bool}|WP_Error
	 */
	private static function agency_account( $email, $name ) {
		if ( ! is_email( $email ) || sanitize_email( $email ) !== $email ) {
			return new WP_Error( 'hop_bad_email', __( 'Adresse courriel invalide pour la connexion.', 'hop-connector' ), array( 'status' => 400 ) );
		}

		$user = get_user_by( 'email', $email );

		if ( $user ) {
			if ( ! user_can( $user, 'manage_options' ) ) {
				return new WP_Error(
					'hop_account_not_admin',
					/* translators: %s: email address. */
					sprintf( __( 'Le compte %s existe sur ce site sans être administrateur. Donnez-lui ce rôle ou supprimez-le, puis réessayez.', 'hop-connector' ), $email ),
					array( 'status' => 409 )
				);
			}

			return array(
				'user'    => $user,
				'created' => false,
			);
		}

		$name    = sanitize_text_field( $name );
		$user_id = wp_insert_user(
			array(
				'user_login'   => self::unused_login( $email ),
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 64, true, true ),
				'display_name' => '' !== $name ? $name : $email,
				'nickname'     => '' !== $name ? $name : $email,
				'role'         => 'administrator',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return new WP_Error( 'hop_account_failed', __( 'Impossible de créer le compte de l\'agence : ', 'hop-connector' ) . $user_id->get_error_message(), array( 'status' => 500 ) );
		}

		update_user_meta( $user_id, self::AGENCY_META, time() );

		$login = (string) get_userdata( $user_id )->user_login;

		Hop_Connector_Log::record( 'account_created', array( 'note' => $login . ' <' . $email . '>' ) );
		Hop_Connector_Log::notify(
			__( 'Un compte administrateur a été créé pour l’agence', 'hop-connector' ),
			sprintf(
				/* translators: 1: login name, 2: email address. */
				__( "Le Connecteur Espace client vient de créer le compte administrateur %1\$s (%2\$s), pour un membre de l’agence qui se connecte depuis la plateforme.\n\nCe compte n’accepte ni mot de passe ni réinitialisation : il n’ouvre que par la plateforme. Il est supprimé quand le site est déconnecté.\n\nSi cette création n’était pas prévue : déconnecter le site dans Réglages > Espace client, puis prévenir l’agence.", 'hop-connector' ),
				$login,
				$email
			)
		);

		return array(
			'user'    => get_user_by( 'id', $user_id ),
			'created' => true,
		);
	}

	/**
	 * A login name made from the email address, not taken yet.
	 *
	 * @param  string $email  Email address.
	 * @return string
	 */
	private static function unused_login( $email ) {
		$base  = sanitize_user( (string) strstr( $email, '@', true ), true );
		$base  = '' !== $base ? $base : 'agence';
		$login = $base;

		for ( $suffix = 2; username_exists( $login ); ++$suffix ) {
			$login = $base . '-' . $suffix;
		}

		return $login;
	}

	/**
	 * Consume a login token found in the URL.
	 *
	 * The token itself is the credential, so no WordPress nonce applies here.
	 *
	 * @return void
	 */
	public static function maybe_consume() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the single-use token.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// Only the visit itself uses the single-use link: a browser may first
		// send OPTIONS (CORS preflight), and link checkers send HEAD.
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'GET' !== sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) {
			return;
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticated by the single-use token.
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

		nocache_headers();

		if ( ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
			wp_die( esc_html__( 'Lien de connexion invalide.', 'hop-connector' ), '', array( 'response' => 403 ) );
		}

		$option = self::OPTION_PREFIX . hash( 'sha256', $token );
		$data   = get_option( $option );

		// delete_option() only succeeds once: two simultaneous requests cannot both log in.
		if ( ! is_array( $data ) || ! delete_option( $option ) || (int) $data['expires'] < time() ) {
			wp_die( esc_html__( 'Lien de connexion expiré ou déjà utilisé.', 'hop-connector' ), '', array( 'response' => 403 ) );
		}

		$user = get_user_by( 'id', (int) $data['user_id'] );

		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			wp_die( esc_html__( 'Lien de connexion invalide.', 'hop-connector' ), '', array( 'response' => 403 ) );
		}

		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );

		/** This action is documented in wp-includes/user.php */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired like wp_signon() does.
		do_action( 'wp_login', $user->user_login, $user );

		wp_safe_redirect( admin_url() );
		exit;
	}

	/**
	 * Delete the tokens nobody used before they expired (hourly cron).
	 *
	 * @return void
	 */
	public static function purge_expired() {
		global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off maintenance query on our own rows.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 500",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%'
			)
		);

		foreach ( (array) $rows as $row ) {
			$data = maybe_unserialize( $row->option_value );

			if ( ! is_array( $data ) || (int) ( $data['expires'] ?? 0 ) < time() ) {
				delete_option( $row->option_name );
			}
		}
	}

	/**
	 * Count one more link in the last hour, unless the hour is full.
	 *
	 * @return bool Whether a link may be issued.
	 */
	private static function take_rate() {
		$since  = time() - HOUR_IN_SECONDS;
		$issued = array_values(
			array_filter(
				(array) get_option( self::OPTION_RATE, array() ),
				static function ( $time ) use ( $since ) {
					return (int) $time > $since;
				}
			)
		);

		if ( count( $issued ) >= self::MAX_PER_HOUR ) {
			return false;
		}

		$issued[] = time();
		update_option( self::OPTION_RATE, $issued, false );

		return true;
	}

	/**
	 * Whether an account is one the connector created for the agency.
	 *
	 * @param  int $user_id  User ID.
	 * @return bool
	 */
	public static function is_agency_account( $user_id ) {
		return (int) $user_id > 0 && '' !== (string) get_user_meta( (int) $user_id, self::AGENCY_META, true );
	}

	/**
	 * No password reset for an agency account: it opens through the platform
	 * only, so taking an agency member's mailbox does not open the site.
	 *
	 * @param  bool $allow    Whether the reset is allowed.
	 * @param  int  $user_id  User ID.
	 * @return bool
	 */
	public static function refuse_password_reset( $allow, $user_id ) {
		return self::is_agency_account( $user_id ) ? false : $allow;
	}

	/**
	 * No password login for an agency account either.
	 *
	 * @param  WP_User|WP_Error|null $user  Result of the earlier checks.
	 * @return WP_User|WP_Error|null
	 */
	public static function refuse_password_login( $user ) {
		if ( $user instanceof WP_User && self::is_agency_account( $user->ID ) ) {
			return new WP_Error( 'hop_agency_account', __( 'Ce compte de l’agence s’ouvre seulement depuis l’espace client.', 'hop-connector' ) );
		}

		return $user;
	}

	/**
	 * Delete the agency accounts, when the site leaves the platform. What they
	 * wrote goes to the oldest administrator who is not one of them; with no
	 * such administrator, nothing is deleted, so the site keeps an admin.
	 *
	 * On a multisite network, deleting a user only takes it off one site, and
	 * the account would stay on the network: nothing is done there, and the
	 * journal says so.
	 *
	 * @return int How many accounts were deleted.
	 */
	public static function remove_agency_accounts() {
		$accounts = get_users(
			array(
				'meta_key' => self::AGENCY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Rare, on disconnection only.
				'fields'   => 'ID',
				'blog_id'  => 0,
			)
		);

		if ( array() === $accounts ) {
			return 0;
		}

		if ( is_multisite() ) {
			Hop_Connector_Log::record( 'accounts_kept', array( 'note' => __( 'multisite : comptes de l’agence à retirer à la main', 'hop-connector' ) ) );

			return 0;
		}

		$heir = null;

		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'ID',
			)
		);

		foreach ( $admins as $admin_id ) {
			if ( ! self::is_agency_account( $admin_id ) ) {
				$heir = (int) $admin_id;
				break;
			}
		}

		if ( null === $heir ) {
			Hop_Connector_Log::record( 'accounts_kept', array( 'note' => __( 'aucun autre administrateur pour reprendre les contenus', 'hop-connector' ) ) );

			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';

		$deleted = 0;

		foreach ( $accounts as $account_id ) {
			if ( wp_delete_user( (int) $account_id, $heir ) ) {
				++$deleted;
			}
		}

		Hop_Connector_Log::record( 'accounts_deleted', array( 'note' => (string) $deleted ) );

		return $deleted;
	}
}
