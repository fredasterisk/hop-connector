<?php
/**
 * Admin screen: pairing token, connection status, IP allowlist.
 *
 * @package Hop_Connector
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings page under Settings > Hop Connector.
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
			__( 'Hop Connector', 'hop-connector' ),
			__( 'Hop Connector', 'hop-connector' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Generate a pairing token and show it once.
	 *
	 * @return void
	 */
	public static function handle_generate() {
		self::guard( 'hop_connector_generate' );

		// Shown on the next page load only, to the user who generated it.
		set_transient( self::TOKEN_TRANSIENT . get_current_user_id(), Hop_Connector_Keys::generate_pending(), 60 );

		self::back();
	}

	/**
	 * Revoke every key.
	 *
	 * @return void
	 */
	public static function handle_disconnect() {
		self::guard( 'hop_connector_disconnect' );

		Hop_Connector_Keys::revoke();

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
		if ( ! current_user_can( 'manage_options' ) ) {
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
			<h1><?php esc_html_e( 'Hop Connector', 'hop-connector' ); ?></h1>

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
			<?php } else { ?>
				<p><?php esc_html_e( 'Ce site n\'est pas encore appairé.', 'hop-connector' ); ?></p>
			<?php } ?>

			<?php if ( $token ) { ?>
				<div class="notice notice-warning inline">
					<p><strong><?php esc_html_e( 'Jeton d\'appairage (affiché une seule fois, valide 15 minutes) :', 'hop-connector' ); ?></strong></p>
					<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( $token ); ?>" onfocus="this.select();" /></p>
					<p><?php esc_html_e( 'Collez-le dans Hop pour terminer l\'appairage.', 'hop-connector' ); ?></p>
				</div>
			<?php } elseif ( $pending ) { ?>
				<p><em><?php esc_html_e( 'Un jeton d\'appairage est en attente d\'utilisation. En générer un nouveau l\'annule.', 'hop-connector' ); ?></em></p>
			<?php } ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<input type="hidden" name="action" value="hop_connector_generate" />
				<?php wp_nonce_field( 'hop_connector_generate' ); ?>
				<?php submit_button( $active ? __( 'Générer un nouveau jeton', 'hop-connector' ) : __( 'Générer un jeton d\'appairage', 'hop-connector' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( $active || $pending ) { ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="hop_connector_disconnect" />
					<?php wp_nonce_field( 'hop_connector_disconnect' ); ?>
					<?php submit_button( __( 'Déconnecter ce site', 'hop-connector' ), 'delete', 'submit', false ); ?>
				</form>
			<?php } ?>

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
		</div>
		<?php
	}

	/**
	 * Capability and nonce check shared by the form handlers.
	 *
	 * @param  string $action  Nonce action.
	 * @return void
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
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
