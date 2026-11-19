<?php
/**
 * Journal kept on the site, and notices sent to its administrator.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * What the platform did on this site, as the site saw it.
 *
 * The journal is one of the connector's own options: copies of the database
 * leave it out, and a restoration puts it back as it was just before, so an
 * old copy cannot erase what happened since.
 */
class Hop_Connector_Log {

	const OPTION = 'hop_connector_log';

	const KEEP = 200;

	/**
	 * Add an entry, newest first.
	 *
	 * @param  string $event   What happened: request, denied, key_replaced, account_created...
	 * @param  array  $detail  route, key_id, result, note.
	 * @return void
	 */
	public static function record( $event, array $detail = array() ) {
		$entries = self::entries();

		array_unshift(
			$entries,
			array(
				'time'   => time(),
				'event'  => (string) $event,
				'route'  => isset( $detail['route'] ) ? substr( (string) $detail['route'], 0, 100 ) : '',
				'ip'     => self::ip(),
				'key_id' => isset( $detail['key_id'] ) ? substr( (string) $detail['key_id'], 0, 64 ) : '',
				'result' => isset( $detail['result'] ) ? substr( (string) $detail['result'], 0, 40 ) : '',
				'note'   => isset( $detail['note'] ) ? substr( (string) $detail['note'], 0, 200 ) : '',
			)
		);

		update_option( self::OPTION, array_slice( $entries, 0, self::KEEP ), false );
	}

	/**
	 * Entries, newest first.
	 *
	 * @param  int $limit  How many; 0 for all.
	 * @return list<array{time:int,event:string,route:string,ip:string,key_id:string,result:string,note:string}>
	 */
	public static function entries( $limit = 0 ) {
		$entries = get_option( self::OPTION, array() );
		$entries = is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
		$entries = $limit > 0 ? array_slice( $entries, 0, $limit ) : $entries;
		$blank   = array(
			'time'   => 0,
			'event'  => '',
			'route'  => '',
			'ip'     => '',
			'key_id' => '',
			'result' => '',
			'note'   => '',
		);

		return array_map(
			static function ( $entry ) use ( $blank ) {
				return array_merge( $blank, array_intersect_key( $entry, $blank ) );
			},
			$entries
		);
	}

	/**
	 * Tell the site's administrator, by email, what the connector just did.
	 *
	 * @param  string $subject  Subject, without the site name.
	 * @param  string $message  Plain text body.
	 * @return void
	 */
	public static function notify( $subject, $message ) {
		$to = get_option( 'admin_email' );

		if ( ! is_email( $to ) || ! function_exists( 'wp_mail' ) ) {
			return;
		}

		$site = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );

		$message .= "\n\n" . sprintf(
			/* translators: 1: site address, 2: settings page address. */
			__( "Site : %1\$s\nJournal du connecteur : %2\$s", 'hop-connector' ),
			home_url(),
			admin_url( 'options-general.php?page=hop-connector' )
		);

		wp_mail( $to, '[' . $site . '] ' . $subject, $message );
	}

	/**
	 * Address of the caller, as the allowlist sees it.
	 *
	 * @return string
	 */
	private static function ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		return 'cli' === PHP_SAPI && '' === $remote ? 'cli' : substr( $remote, 0, 45 );
	}
}
