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
	 * Create a login URL.
	 *
	 * With an email, the URL opens the account of the agency member who asked
	 * (created the first time), never the client's own account: what the agency
	 * does in wp-admin shows under its own name, and the client's two-factor
	 * login stays theirs. Without one, as older platforms ask, it opens the
	 * requested or the oldest administrator.
	 *
	 * @param  int    $user_id  Requested user, or 0 for the oldest administrator.
	 * @param  string $email    Email address of the agency member, verified by the platform.
	 * @param  string $name     Name shown for an account created now.
	 * @return array{url:string,user:array{login:string,created:bool}}|WP_Error
	 */
	public static function issue( $user_id, $email = '', $name = '' ) {
		$created = false;

		if ( '' !== $email ) {
			$account = self::agency_account( $email, $name );

			if ( is_wp_error( $account ) ) {
				return $account;
			}

			$user    = $account['user'];
			$created = $account['created'];
		} else {
			$user = $user_id ? get_user_by( 'id', $user_id ) : self::first_administrator();
		}

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
	 * Oldest administrator account.
	 *
	 * @return WP_User|null
	 */
	private static function first_administrator() {
		$users = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
			)
		);

		return $users ? $users[0] : null;
	}
}
