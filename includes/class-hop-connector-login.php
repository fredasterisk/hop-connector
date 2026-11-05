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
	 * Create a login URL for an administrator.
	 *
	 * @param  int $user_id  Requested user, or 0 for the first administrator.
	 * @return string|WP_Error
	 */
	public static function issue( $user_id ) {
		$user = $user_id ? get_user_by( 'id', $user_id ) : self::first_administrator();

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

		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
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
