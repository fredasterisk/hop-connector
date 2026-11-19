<?php
/**
 * Admin screen: pairing token, connection status, IP allowlist, switches of
 * wp-config.php and journal.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page under Settings > Connecteur Espace client.
 */
class Hop_Connector_Admin {

	const PAGE = 'hop-connector';

	const TOKEN_TRANSIENT = 'hop_connector_token_once_';

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'Connecteur Espace client', 'hop-connector' ),
			__( 'Connecteur Espace client', 'hop-connector' ),
			self::capability(),
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Who may pair the site. On a multisite network, only a network
	 * administrator: the connector acts on the whole network, and logs in as
	 * an administrator who may be a super admin.
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * Generate a pairing token and show it once.
	 *
	 * @return void
	 */
	public static function handle_generate() {
		self::guard( 'hop_connector_generate' );

		$locked = self::pairing_lock();

		if ( null !== $locked ) {
			wp_die( esc_html( $locked ), '', array( 'response' => 403 ) );
		}

		$token = Hop_Connector_Keys::generate_pending();

		Hop_Connector_Log::record(
			'pending_key',
			array(
				'key_id' => (string) Hop_Connector_Keys::pending()['key_id'],
				'note'   => wp_get_current_user()->user_login,
			)
		);

		// Shown on the next page load only, to the user who generated it.
		set_transient( self::TOKEN_TRANSIENT . get_current_user_id(), $token, 60 );

		self::back();
	}

	/**
	 * Why this screen may not pair the site now, or null when it may.
	 *
	 * A token pasted in the platform replaces the active key: from here, an
	 * administrator kept from installing code (DISALLOW_FILE_MODS) could
	 * hand the site to a platform of their own, and read its files through it.
	 * WP-CLI, run by whoever has the server, still pairs.
	 *
	 * @return string|null
	 */
	public static function pairing_lock() {
		if ( in_array( 'pair', Hop_Connector_Guard::denied(), true ) ) {
			return __( 'L’appairage depuis cet écran est coupé par HOP_CONNECTOR_DENY, dans wp-config.php. Utiliser WP-CLI : wp hop-connector pair.', 'hop-connector' );
		}

		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS && Hop_Connector_Keys::active() ) {
			return __( 'Ce site interdit les modifications de fichiers (DISALLOW_FILE_MODS) : la clé de la plateforme ne se remplace pas depuis cet écran. Déconnecter le site d’abord, ou utiliser WP-CLI : wp hop-connector pair --force.', 'hop-connector' );
		}

		return null;
	}

	/**
	 * Revoke every key, and delete the accounts made for the agency.
	 *
	 * @return void
	 */
	public static function handle_disconnect() {
		self::guard( 'hop_connector_disconnect' );

		Hop_Connector_Keys::revoke();
		Hop_Connector_Log::record( 'disconnected', array( 'note' => wp_get_current_user()->user_login ) );
		Hop_Connector_Login::remove_agency_accounts();

		self::back();
	}

	/**
	 * Save the IP allowlist.
	 *
	 * @return void
	 */
	public static function handle_save() {
		self::guard( 'hop_connector_save' );

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in guard().
		$raw = isset( $_POST['allowed_ips'] ) ? sanitize_textarea_field( wp_unslash( $_POST['allowed_ips'] ) ) : '';
		$ips = array();

		foreach ( preg_split( '/[\s,]+/', $raw ) as $ip ) {
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$ips[] = $ip;
			}
		}

		update_option( Hop_Connector_Auth::OPTION_ALLOWED_IPS, implode( "\n", array_unique( $ips ) ), false );

		self::back();
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$active  = Hop_Connector_Keys::active();
		$pending = Hop_Connector_Keys::pending();
		$token   = get_transient( self::TOKEN_TRANSIENT . get_current_user_id() );

		if ( $token ) {
			delete_transient( self::TOKEN_TRANSIENT . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Connecteur Espace client', 'hop-connector' ); ?></h1>

			<h2><?php esc_html_e( 'Connexion à la plateforme', 'hop-connector' ); ?></h2>

			<?php if ( $active ) { ?>
				<p>
					<?php
					printf(
						/* translators: 1: key identifier, 2: pairing date. */
						esc_html__( 'Site appairé (clé %1$s) depuis le %2$s.', 'hop-connector' ),
						'<code>' . esc_html( $active['key_id'] ) . '</code>',
						esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $active['paired_at'] ) )
					);
					?>
				</p>
				<p class="description"><?php esc_html_e( 'Ce site ne garde que la clé publique de la plateforme : lire sa base de données ne permet pas de signer une requête.', 'hop-connector' ); ?></p>
			<?php } elseif ( Hop_Connector_Keys::has_legacy_secret() ) { ?>
				<div class="notice notice-error inline">
					<p><?php esc_html_e( 'Ce site était appairé par un secret partagé, que le connecteur n’accepte plus. Générer un nouveau jeton et le coller dans l’espace client pour l’appairer de nouveau.', 'hop-connector' ); ?></p>
				</div>
			<?php } else { ?>
				<p><?php esc_html_e( 'Ce site n\'est pas encore appairé.', 'hop-connector' ); ?></p>
			<?php } ?>

			<?php if ( $token ) { ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Jeton d\'appairage (affiché une seule fois, valide 15 minutes) :', 'hop-connector' ); ?></strong></p>
					<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $token ); ?>" onfocus="this.select();" /></p>
					<p><?php esc_html_e( 'Collez-le dans l’espace client pour terminer l\'appairage.', 'hop-connector' ); ?></p>
				</div>
			<?php } elseif ( $pending ) { ?>
				<p><em><?php esc_html_e( 'Un jeton d\'appairage est en attente d\'utilisation. En générer un nouveau l\'annule.', 'hop-connector' ); ?></em></p>
			<?php } ?>

			<?php $locked = self::pairing_lock(); ?>
			<?php if ( null !== $locked ) { ?>
				<p class="description"><?php echo esc_html( $locked ); ?></p>
			<?php } else { ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
					<input type="hidden" name="action" value="hop_connector_generate" />
					<?php wp_nonce_field( 'hop_connector_generate' ); ?>
					<?php submit_button( $active ? __( 'Générer un nouveau jeton', 'hop-connector' ) : __( 'Générer un jeton d\'appairage', 'hop-connector' ), 'primary', 'submit', false ); ?>
				</form>
			<?php } ?>

			<?php if ( $active || $pending ) { ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="hop_connector_disconnect" />
					<?php wp_nonce_field( 'hop_connector_disconnect' ); ?>
					<?php submit_button( __( 'Déconnecter ce site', 'hop-connector' ), 'delete', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'Déconnecter retire la clé de la plateforme et supprime les comptes créés pour l’agence ; leurs contenus passent au plus ancien administrateur.', 'hop-connector' ); ?></p>
			<?php } ?>

			<?php self::render_switches(); ?>

			<h2><?php esc_html_e( 'Liste blanche d\'adresses IP (optionnelle)', 'hop-connector' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hop_connector_save" />
				<?php wp_nonce_field( 'hop_connector_save' ); ?>
				<p>
					<textarea name="allowed_ips" rows="4" class="large-text code" placeholder="203.0.113.10"><?php echo esc_textarea( (string) get_option( Hop_Connector_Auth::OPTION_ALLOWED_IPS, '' ) ); ?></textarea>
				</p>
				<p class="description"><?php esc_html_e( 'Une adresse par ligne. Vide : aucune restriction. Si la liste est remplie, seules ces adresses peuvent appeler le connecteur.', 'hop-connector' ); ?></p>
				<?php submit_button( __( 'Enregistrer', 'hop-connector' ) ); ?>
			</form>

			<?php self::render_journal(); ?>
		</div>
		<?php
	}

	/**
	 * What wp-config.php cuts, and how to cut more.
	 *
	 * @return void
	 */
	private static function render_switches() {
		$denied = Hop_Connector_Guard::denied();
		?>
		<h2><?php esc_html_e( 'Actions coupées sur ce site', 'hop-connector' ); ?></h2>
		<?php if ( Hop_Connector_Guard::disabled() ) { ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'HOP_CONNECTOR_DISABLED : la plateforme ne peut plus rien demander à ce site.', 'hop-connector' ); ?></p></div>
		<?php } elseif ( array() === $denied ) { ?>
			<p><?php esc_html_e( 'Aucune : la plateforme peut faire toutes les actions du connecteur.', 'hop-connector' ); ?></p>
		<?php } else { ?>
			<ul style="list-style:disc;padding-left:20px;">
				<?php foreach ( $denied as $action ) { ?>
					<li><code><?php echo esc_html( $action ); ?></code> : <?php echo esc_html( Hop_Connector_Guard::actions()[ $action ] ); ?></li>
				<?php } ?>
			</ul>
		<?php } ?>
		<p class="description">
			<?php esc_html_e( 'Ces réglages se font dans wp-config.php, que la plateforme ne peut pas modifier. Par exemple :', 'hop-connector' ); ?>
			<code>define( 'HOP_CONNECTOR_DENY', 'login,restore' );</code>
			<?php
			printf(
				/* translators: %s: list of action names. */
				esc_html__( 'Actions possibles : %s. Pour tout couper : HOP_CONNECTOR_DISABLED à true. Après un changement dans wp-config.php, vider le cache du serveur (OPcache, cache d’objets) ou redéployer : sinon l’ancienne version du fichier reste en service.', 'hop-connector' ),
				esc_html( implode( ', ', array_keys( Hop_Connector_Guard::actions() ) ) )
			);
			?>
		</p>
		<?php
	}

	/**
	 * The last entries of the journal.
	 *
	 * @return void
	 */
	private static function render_journal() {
		$entries = Hop_Connector_Log::entries( 50 );
		$format  = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<h2><?php esc_html_e( 'Journal du connecteur', 'hop-connector' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Les demandes de la plateforme qui changent le site, les refus et les changements de clé, les plus récents d’abord. Une restauration de la base ne l’efface pas.', 'hop-connector' ); ?></p>
		<?php if ( array() === $entries ) { ?>
			<p><?php esc_html_e( 'Rien pour l’instant.', 'hop-connector' ); ?></p>
		<?php } else { ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'hop-connector' ); ?></th>
						<th><?php esc_html_e( 'Événement', 'hop-connector' ); ?></th>
						<th><?php esc_html_e( 'Route', 'hop-connector' ); ?></th>
						<th><?php esc_html_e( 'Résultat', 'hop-connector' ); ?></th>
						<th><?php esc_html_e( 'Adresse IP', 'hop-connector' ); ?></th>
						<th><?php esc_html_e( 'Clé', 'hop-connector' ); ?></th>
						<th><?php esc_html_e( 'Détail', 'hop-connector' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $entries as $entry ) { ?>
						<tr>
							<td><?php echo esc_html( wp_date( $format, (int) $entry['time'] ) ); ?></td>
							<td><?php echo esc_html( $entry['event'] ); ?></td>
							<td><code><?php echo esc_html( $entry['route'] ); ?></code></td>
							<td><?php echo esc_html( $entry['result'] ); ?></td>
							<td><?php echo esc_html( $entry['ip'] ); ?></td>
							<td><code><?php echo esc_html( $entry['key_id'] ); ?></code></td>
							<td><?php echo esc_html( $entry['note'] ); ?></td>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		<?php } ?>
		<?php
	}

	/**
	 * Capability and nonce check shared by the form handlers.
	 *
	 * @param  string $action  Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Permission refusée.', 'hop-connector' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Redirect back to the settings page.
	 *
	 * @return void
	 */
	private static function back() {
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}
}
