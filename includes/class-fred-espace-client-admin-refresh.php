<?php
/**
 * Checks for updates the way the WordPress admin does.
 *
 * Some premium plugins only load their updater in the admin, during WP-Cron
 * or under WP-CLI: Blocksy Companion Pro and every plugin built on Freemius
 * check is_admin() before anything else. A REST request is none of these, so
 * a check made from it never hears of their updates and, worse, rewrites
 * the list without them. The check is therefore run in a second request to
 * admin-ajax.php, which WordPress loads as the admin, unlocked by a token
 * that only the REST request knows and that works once.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * The update check made in the admin context, through a loopback request.
 */
class Fred_Espace_Client_Admin_Refresh {

	const ACTION = 'fred_espace_client_refresh';

	const OPTION_TOKEN = 'fred_espace_client_refresh_token';

	/**
	 * How long the token works, in seconds.
	 */
	const TOKEN_TTL = 120;

	/**
	 * How long the loopback request may take, leaving Hop's own timeout room
	 * for the check made here if it fails.
	 */
	const TIMEOUT = 45;

	/**
	 * Check for updates and return the fresh inventory.
	 *
	 * The check runs in the admin context when the site can call itself;
	 * otherwise it runs here, keeping the updates already known that the
	 * check could not see.
	 *
	 * @return array<string,mixed>
	 */
	public static function refresh_and_report() {
		$inventory = self::through_admin();

		if ( is_array( $inventory ) ) {
			return $inventory;
		}

		Fred_Espace_Client_Log::record(
			'refresh_local',
			array(
				'route'  => '/updates/refresh',
				'result' => 'fallback',
				'note'   => $inventory->get_error_message(),
			)
		);

		Fred_Espace_Client_Updater::refresh();

		return Fred_Espace_Client_Inventory::inventory();
	}

	/**
	 * Ask the site itself, through admin-ajax.php, to check for updates.
	 *
	 * @return array<string,mixed>|WP_Error  The inventory seen from the admin.
	 */
	public static function through_admin() {
		$token = wp_generate_password( 40, false, false );

		update_option(
			self::OPTION_TOKEN,
			array(
				'hash'    => hash( 'sha256', $token ),
				'expires' => time() + self::TOKEN_TTL,
			),
			false
		);

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => self::TIMEOUT,
				// Same rule as WordPress's own loopback requests (Site Health, WP-Cron).
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'body'      => array(
					'action' => self::ACTION,
					'token'  => $token,
				),
			)
		);

		// Whatever happened, the token is spent.
		delete_option( self::OPTION_TOKEN );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['success'] ) || ! isset( $body['data']['plugins'], $body['data']['themes'], $body['data']['core'] ) ) {
			return new WP_Error(
				'hop_loopback_failed',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Requête de l’admin refusée ou illisible (HTTP %d).', 'fred-espace-client' ), $code )
			);
		}

		return $body['data'];
	}

	/**
	 * The admin-ajax.php side: check for updates as the admin, and answer
	 * with the inventory. Anyone can reach it; only the current token opens it.
	 *
	 * @return void
	 */
	public static function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- The one-time token stands for the nonce: no user session is involved.
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( ! self::consume( $token ) ) {
			wp_send_json_error( null, 403 );
		}

		Fred_Espace_Client_Updater::refresh();

		wp_send_json_success( Fred_Espace_Client_Inventory::inventory() );
	}

	/**
	 * Whether the token is the current one, still in time. It works only once.
	 *
	 * @param  string $token  Token received.
	 * @return bool
	 */
	public static function consume( $token ) {
		$stored = get_option( self::OPTION_TOKEN );

		if ( ! is_array( $stored ) || ! isset( $stored['hash'], $stored['expires'] ) || '' === $token ) {
			return false;
		}

		// A wrong token leaves the right one in place: a stranger cannot spoil the check.
		if ( ! hash_equals( (string) $stored['hash'], hash( 'sha256', $token ) ) ) {
			return false;
		}

		delete_option( self::OPTION_TOKEN );

		return (int) $stored['expires'] >= time();
	}
}
