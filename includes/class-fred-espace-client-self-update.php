<?php
/**
 * Updates of the connector itself, served by the platform.
 *
 * @package Fred_Espace_Client
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells WordPress about new versions of the connector, read from the platform
 * instead of GitHub: no token in wp-config.php, no GitHub limit per address.
 *
 * WordPress asks through the filter named after the host of the Update URI
 * header. The zip it then downloads is installed only once
 * Fred_Espace_Client_Release has checked the agency's signature: whoever answers
 * here cannot make the site install anything else.
 */
class Fred_Espace_Client_Self_Update {

	/**
	 * The platform, as in the Update URI header.
	 */
	const PLATFORM = 'https://client.fredrobert.com';

	/**
	 * Where the platform serves this name of the connector. The former name,
	 * hop-connector, has its own address, frozen at its last version.
	 */
	const PATH = '/connector/fred-espace-client/';

	/**
	 * Site transient holding the latest version read from the platform.
	 */
	const CACHE = 'fred_espace_client_latest';

	/**
	 * Hook into the update checks of WordPress.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'update_plugins_' . wp_parse_url( self::PLATFORM, PHP_URL_HOST ), array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'forget' ), 10, 0 );
	}

	/**
	 * Address of the platform. A development copy of the platform can be set
	 * in wp-config.php with FRED_ESPACE_CLIENT_PLATFORM_URL (or the former
	 * HOP_CONNECTOR_PLATFORM_URL).
	 *
	 * @return string Without a trailing slash.
	 */
	public static function platform_url() {
		foreach ( array( 'FRED_ESPACE_CLIENT_PLATFORM_URL', 'HOP_CONNECTOR_PLATFORM_URL' ) as $constant ) {
			if ( defined( $constant ) && is_string( constant( $constant ) ) && 1 === preg_match( '#^https?://[^/\s]+#', constant( $constant ) ) ) {
				return untrailingslashit( constant( $constant ) );
			}
		}

		return self::PLATFORM;
	}

	/**
	 * The update of the connector, for wp_update_plugins().
	 *
	 * @param  array|false $update       Update found so far.
	 * @param  array       $plugin_data  Headers of the plugin.
	 * @param  string      $plugin_file  Plugin basename.
	 * @return array|false
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( FRED_ESPACE_CLIENT_FILE ) !== $plugin_file ) {
			return $update;
		}

		$release = self::latest();

		if ( null === $release ) {
			return $update;
		}

		return array(
			'id'           => self::platform_url() . self::PATH,
			'slug'         => dirname( $plugin_file ),
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => self::platform_url(),
			'package'      => $release['download_url'],
			'requires'     => $release['requires'],
			'requires_php' => $release['requires_php'],
		);
	}

	/**
	 * What the « View details » window shows for the connector.
	 *
	 * @param  false|object|array $result  Answer so far.
	 * @param  string             $action  Requested action.
	 * @param  object             $args    Arguments, with the slug.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) || dirname( plugin_basename( FRED_ESPACE_CLIENT_FILE ) ) !== $args->slug ) {
			return $result;
		}

		$release = self::latest();

		if ( null === $release ) {
			return $result;
		}

		return (object) array(
			'name'          => __( 'Connecteur Espace client', 'fred-espace-client' ),
			'slug'          => $args->slug,
			'version'       => $release['version'],
			'author'        => 'Fred Robert',
			'homepage'      => self::platform_url(),
			'download_link' => $release['download_url'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'last_updated'  => $release['published_at'],
			'sections'      => array( 'changelog' => wpautop( esc_html( $release['changelog'] ) ) ),
		);
	}

	/**
	 * Forget the version read, so the next check asks the platform again.
	 *
	 * @return void
	 */
	public static function forget() {
		delete_site_transient( self::CACHE );
	}

	/**
	 * The latest version the platform serves, read at most once an hour; null
	 * when the platform cannot be read or answers something unexpected.
	 *
	 * @return array{version:string,download_url:string,requires:string,requires_php:string,published_at:string,changelog:string}|null
	 */
	public static function latest() {
		$cached = get_site_transient( self::CACHE );

		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$release = self::fetch();

		// A failure is retried after 15 minutes, not at every page of wp-admin.
		set_site_transient( self::CACHE, null === $release ? array() : $release, null === $release ? 15 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Read latest.json on the platform.
	 *
	 * @return array{version:string,download_url:string,requires:string,requires_php:string,published_at:string,changelog:string}|null
	 */
	private static function fetch() {
		$response = wp_remote_get(
			self::platform_url() . self::PATH . 'latest.json',
			array(
				'timeout'     => 10,
				'redirection' => 2,
				'headers'     => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data['version'], $data['download_url'] ) ) {
			return null;
		}

		$version = (string) $data['version'];
		$package = (string) $data['download_url'];

		// Only a version number, and only a zip of the platform itself.
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) || 0 !== strpos( $package, self::platform_url() . self::PATH ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'download_url' => $package,
			'requires'     => isset( $data['requires'] ) ? (string) $data['requires'] : '',
			'requires_php' => isset( $data['requires_php'] ) ? (string) $data['requires_php'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
			'changelog'    => isset( $data['changelog'] ) ? (string) $data['changelog'] : '',
		);
	}
}
