<?php
/**
 * Switches set in wp-config.php, out of the platform's reach.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the site refuses even from a correctly signed request.
 *
 * The connector never writes wp-config.php, and a request from the platform
 * cannot change a constant: a site that cuts an action here keeps it cut,
 * whoever holds the platform's key.
 *
 *     define( 'HOP_CONNECTOR_DISABLED', true );           // Every signed request refused.
 *     define( 'HOP_CONNECTOR_DENY', 'login,restore' );    // Only these actions refused.
 */
class Hop_Connector_Guard {

	/**
	 * Action of each signed route. A route left out (status, inventory,
	 * check for updates) stays allowed: it reads, and changes nothing.
	 *
	 * @var array<string, string>
	 */
	const ROUTES = array(
		'/hop/v1/login-token'                        => 'login',
		'/hop/v1/backups/database/restore'           => 'restore',
		'/hop/v1/backups/database/restore/start'     => 'restore',
		'/hop/v1/backups/database/restore/continue'  => 'restore',
		'/hop/v1/rollback'                           => 'restore',
		'/hop/v1/rescue/rollback'                    => 'restore',
		'/hop/v1/backups/database'                   => 'database',
		'/hop/v1/backups/database/start'             => 'database',
		'/hop/v1/backups/database/continue'          => 'database',
		'/hop/v1/backups/pull/database'              => 'pull',
		'/hop/v1/backups/pull/database/start'        => 'pull',
		'/hop/v1/backups/pull/database/continue'     => 'pull',
		'/hop/v1/backups/pull/chunk'                 => 'pull',
		'/hop/v1/backups/pull/done'                  => 'pull',
		'/hop/v1/backups/pull/files'                 => 'pull',
		'/hop/v1/backups/pull/fetch'                 => 'pull',
		'/hop/v1/plugins/state'                      => 'plugins',
		'/hop/v1/updates/apply'                      => 'updates',
	);

	/**
	 * Routes that begin an operation, written to the journal. The steps that
	 * follow (continue, chunk, fetch) and the routine reads are not: a single
	 * backup sends hundreds of them, and would push everything else out.
	 *
	 * @var list<string>
	 */
	const JOURNALED = array(
		'/hop/v1/handshake',
		'/hop/v1/login-token',
		'/hop/v1/backups/database/restore',
		'/hop/v1/backups/database/restore/start',
		'/hop/v1/rollback',
		'/hop/v1/rescue/rollback',
		'/hop/v1/backups/database',
		'/hop/v1/backups/database/start',
		'/hop/v1/backups/pull/database',
		'/hop/v1/backups/pull/database/start',
		'/hop/v1/plugins/state',
		'/hop/v1/updates/apply',
	);

	/**
	 * Every action a site may cut, with what it covers.
	 *
	 * @return array<string, string>
	 */
	public static function actions() {
		return array(
			'login'    => __( 'connexion à wp-admin depuis la plateforme', 'hop-connector' ),
			'restore'  => __( 'restauration de la base et retour à la version précédente d’une extension', 'hop-connector' ),
			'database' => __( 'copie de la base avant les mises à jour', 'hop-connector' ),
			'pull'     => __( 'tirage des fichiers et de la base pour la sauvegarde', 'hop-connector' ),
			'plugins'  => __( 'activation, désactivation et suppression d’extensions', 'hop-connector' ),
			'updates'  => __( 'application des mises à jour', 'hop-connector' ),
			'pair'     => __( 'nouvel appairage depuis cet écran de réglages', 'hop-connector' ),
		);
	}

	/**
	 * Whether HOP_CONNECTOR_DISABLED cuts every signed request.
	 *
	 * @return bool
	 */
	public static function disabled() {
		return defined( 'HOP_CONNECTOR_DISABLED' ) && true === HOP_CONNECTOR_DISABLED;
	}

	/**
	 * Actions listed in HOP_CONNECTOR_DENY that the connector knows.
	 *
	 * @return list<string>
	 */
	public static function denied() {
		if ( ! defined( 'HOP_CONNECTOR_DENY' ) || ! is_string( HOP_CONNECTOR_DENY ) ) {
			return array();
		}

		$listed = array_map( 'trim', explode( ',', strtolower( HOP_CONNECTOR_DENY ) ) );

		return array_values( array_intersect( array_keys( self::actions() ), $listed ) );
	}

	/**
	 * Whether one action is cut on this site.
	 *
	 * @param  string $action  Name from actions().
	 * @return bool
	 */
	public static function is_denied( $action ) {
		return self::disabled() || in_array( $action, self::denied(), true );
	}

	/**
	 * Action of a signed route, or null for a route that only reads.
	 *
	 * @param  string $route  Signed route, /hop/v1/...
	 * @return string|null
	 */
	public static function action_of( $route ) {
		return self::ROUTES[ $route ] ?? null;
	}

	/**
	 * The refusal for a signed route, or null when the site allows it.
	 *
	 * @param  string $route  Signed route.
	 * @return WP_Error|null
	 */
	public static function refusal( $route ) {
		if ( self::disabled() ) {
			return new WP_Error(
				'hop_action_denied',
				__( 'Le connecteur est coupé sur ce site par HOP_CONNECTOR_DISABLED, dans wp-config.php.', 'hop-connector' ),
				array(
					'status' => 403,
					'action' => 'all',
				)
			);
		}

		$action = self::action_of( $route );

		if ( null === $action || ! in_array( $action, self::denied(), true ) ) {
			return null;
		}

		return new WP_Error(
			'hop_action_denied',
			/* translators: %s: action name. */
			sprintf( __( 'L’action « %s » est coupée sur ce site par HOP_CONNECTOR_DENY, dans wp-config.php.', 'hop-connector' ), $action ),
			array(
				'status' => 403,
				'action' => $action,
			)
		);
	}

	/**
	 * Whether an accepted request goes to the journal: the routes that begin
	 * an operation, and the first page of the list of files of a pull.
	 *
	 * @param  string $route  Signed route.
	 * @param  string $body   Raw JSON body.
	 * @return bool
	 */
	public static function journaled( $route, $body ) {
		if ( '/hop/v1/backups/pull/files' === $route ) {
			$payload = json_decode( $body, true );

			return ! is_array( $payload ) || empty( $payload['after'] );
		}

		return in_array( $route, self::JOURNALED, true );
	}
}
