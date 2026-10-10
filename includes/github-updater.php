<?php
/**
 * Mises à jour en un clic depuis les releases GitHub (dépôts privés compris).
 *
 * Fichier embarqué tel quel dans chaque thème / extension. Ne pas le modifier
 * dans un dépôt : la copie de référence vit dans la skill « wp-github-updater »
 * et /bcr remplace toute copie dont le numéro ci-dessous est plus ancien.
 *
 * Updater-Version: 1.0.0
 *
 * Mise en place :
 *   1. En-tête `Update URI: https://github.com/<compte>/<depot>` dans le fichier
 *      principal de l'extension, ou dans le style.css du thème.
 *   2. Dans ce même fichier principal (ou functions.php pour un thème) :
 *        require_once __DIR__ . '/inc/github-updater.php';
 *        Asterisk_GitHub_Updater::register( __FILE__ );
 *   3. Dépôt privé : dans wp-config.php,
 *        define( 'ASTERISK_GITHUB_TOKEN', 'github_pat_…' );
 *
 * Le zip téléchargé est le premier asset .zip de la dernière release ; à défaut,
 * l'archive du code source au tag de la release.
 */

defined( 'ABSPATH' ) || exit;

// Plusieurs thèmes / extensions du même site embarquent ce fichier : la première
// copie chargée sert pour tous.
if ( ! class_exists( 'Asterisk_GitHub_Updater' ) ) {

    class Asterisk_GitHub_Updater {

        const VERSION = '1.0.0';
        const API     = 'https://api.github.com/repos/';

        /** @var array<string,true> Extensions gérées, par basename (dossier/fichier.php). */
        private static $plugins = [];

        /** @var array<string,true> Thèmes gérés, par nom de dossier. */
        private static $themes = [];

        /** @var bool */
        private static $hooked = false;

        /**
         * Déclare un thème ou une extension à mettre à jour depuis GitHub.
         *
         * @param string $file Fichier principal de l'extension, ou functions.php du thème.
         */
        public static function register( string $file ): void {
            $dir = wp_normalize_path( dirname( $file ) );

            if ( $dir === wp_normalize_path( get_stylesheet_directory() ) ) {
                self::$themes[ get_stylesheet() ] = true;
            } elseif ( $dir === wp_normalize_path( get_template_directory() ) ) {
                self::$themes[ get_template() ] = true;
            } else {
                self::$plugins[ plugin_basename( $file ) ] = true;
            }

            if ( self::$hooked ) {
                return;
            }
            self::$hooked = true;

            // Filtres natifs déclenchés par l'en-tête « Update URI » (hôte github.com).
            add_filter( 'update_plugins_github.com', [ __CLASS__, 'check_plugin' ], 10, 3 );
            add_filter( 'update_themes_github.com', [ __CLASS__, 'check_theme' ], 10, 3 );
            add_filter( 'upgrader_pre_download', [ __CLASS__, 'download' ], 10, 4 );
            add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_source_dir' ], 10, 4 );
            add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 10, 3 );
        }

        /**
         * @param array|false $update
         * @param array       $plugin_data
         * @param string      $plugin_file
         * @return array|false
         */
        public static function check_plugin( $update, $plugin_data, $plugin_file ) {
            if ( empty( self::$plugins[ $plugin_file ] ) ) {
                return $update;
            }
            $release = self::latest_release( (string) $plugin_data['UpdateURI'] );
            if ( ! $release ) {
                return $update;
            }

            return [
                'slug'    => dirname( $plugin_file ),
                'plugin'  => $plugin_file,
                'version' => $release['version'],
                'url'     => $release['url'],
                'package' => $release['package'],
            ];
        }

        /**
         * @param array|false $update
         * @param array       $theme_data
         * @param string      $stylesheet
         * @return array|false
         */
        public static function check_theme( $update, $theme_data, $stylesheet ) {
            if ( empty( self::$themes[ $stylesheet ] ) ) {
                return $update;
            }
            $release = self::latest_release( (string) $theme_data['UpdateURI'] );
            if ( ! $release ) {
                return $update;
            }

            return [
                'theme'   => $stylesheet,
                'version' => $release['version'],
                'url'     => $release['url'],
                'package' => $release['package'],
            ];
        }

        /**
         * Télécharge le zip avec le jeton. GitHub répond par une redirection vers
         * une URL signée : on la suit à la main, car renvoyer l'en-tête
         * Authorization à l'hôte de stockage fait échouer le téléchargement.
         *
         * @param bool|WP_Error $reply
         * @param string        $package
         * @param WP_Upgrader   $upgrader
         * @param array         $hook_extra
         * @return bool|string|WP_Error
         */
        public static function download( $reply, $package, $upgrader = null, $hook_extra = [] ) {
            if ( 0 !== strpos( (string) $package, self::API ) || '' === self::managed_slug( $hook_extra ) ) {
                return $reply;
            }

            $accept   = false !== strpos( $package, '/releases/assets/' ) ? 'application/octet-stream' : 'application/vnd.github+json';
            $response = wp_remote_get(
                $package,
                [
                    'headers'     => self::headers( $accept ),
                    'redirection' => 0,
                    'timeout'     => 30,
                ]
            );
            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $location = wp_remote_retrieve_header( $response, 'location' );
            if ( ! $location ) {
                return new WP_Error(
                    'asterisk_ghu_download',
                    sprintf(
                        'GitHub a refusé le téléchargement (HTTP %d). Vérifiez la constante ASTERISK_GITHUB_TOKEN dans wp-config.php.',
                        (int) wp_remote_retrieve_response_code( $response )
                    )
                );
            }

            return download_url( $location, 300 );
        }

        /**
         * Redonne au dossier extrait le nom du thème / de l'extension : l'archive
         * du code source de GitHub s'extrait dans « compte-depot-<sha> ».
         *
         * @param string|WP_Error $source
         * @param string          $remote_source
         * @param WP_Upgrader     $upgrader
         * @param array           $hook_extra
         * @return string|WP_Error
         */
        public static function fix_source_dir( $source, $remote_source, $upgrader = null, $hook_extra = [] ) {
            global $wp_filesystem;

            $slug = self::managed_slug( $hook_extra );
            if ( '' === $slug || is_wp_error( $source ) || basename( $source ) === $slug ) {
                return $source;
            }

            $target = trailingslashit( $remote_source ) . $slug;
            if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $target, true ) ) {
                return new WP_Error( 'asterisk_ghu_rename', 'Impossible de renommer le dossier extrait de la mise à jour.' );
            }

            return trailingslashit( $target );
        }

        /**
         * Alimente la modale « Afficher les détails » des extensions gérées.
         *
         * @param false|object|array $result
         * @param string             $action
         * @param object             $args
         * @return false|object|array
         */
        public static function plugin_info( $result, $action, $args ) {
            if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
                return $result;
            }

            foreach ( array_keys( self::$plugins ) as $basename ) {
                if ( dirname( $basename ) !== $args->slug ) {
                    continue;
                }
                $data    = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );
                $release = self::latest_release( (string) $data['UpdateURI'] );
                if ( ! $release ) {
                    return $result;
                }

                return (object) [
                    'name'          => $data['Name'],
                    'slug'          => $args->slug,
                    'version'       => $release['version'],
                    'author'        => $data['Author'],
                    'homepage'      => $release['url'],
                    'download_link' => $release['package'],
                    'sections'      => [ 'changelog' => wpautop( esc_html( $release['notes'] ) ) ],
                ];
            }

            return $result;
        }

        /**
         * Dernière release publiée du dépôt (hors brouillons et pré-releases).
         *
         * Mise en cache 1 h ; un échec est retenu 15 min pour ne pas ralentir
         * l'admin. « Vérifier à nouveau » (force-check) contourne le cache.
         *
         * @param string $update_uri Valeur de l'en-tête Update URI.
         * @return array{version:string,url:string,package:string,notes:string}|null
         */
        private static function latest_release( string $update_uri ): ?array {
            $repo = trim( (string) wp_parse_url( $update_uri, PHP_URL_PATH ), '/' );
            if ( ! preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) ) {
                return null;
            }

            $key    = 'asterisk_ghu_' . md5( $repo );
            $cached = get_site_transient( $key );
            if ( is_array( $cached ) && empty( $_GET['force-check'] ) ) {
                return $cached ?: null;
            }

            $response = wp_remote_get(
                self::API . $repo . '/releases/latest',
                [
                    'headers' => self::headers(),
                    'timeout' => 10,
                ]
            );
            $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

            if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $data['tag_name'] ) ) {
                set_site_transient( $key, [], 15 * MINUTE_IN_SECONDS );
                return null;
            }

            $package = (string) $data['zipball_url'];
            foreach ( $data['assets'] ?? [] as $asset ) {
                if ( '.zip' === strtolower( substr( (string) $asset['name'], -4 ) ) ) {
                    $package = (string) $asset['url'];
                    break;
                }
            }

            $release = [
                'version' => ltrim( (string) $data['tag_name'], 'vV' ),
                'url'     => (string) $data['html_url'],
                'package' => $package,
                'notes'   => (string) ( $data['body'] ?? '' ),
            ];
            set_site_transient( $key, $release, HOUR_IN_SECONDS );

            return $release;
        }

        /**
         * Nom de dossier du thème / de l'extension en cours de mise à jour, s'il
         * est géré ici ; chaîne vide sinon.
         *
         * @param mixed $hook_extra
         */
        private static function managed_slug( $hook_extra ): string {
            if ( ! empty( $hook_extra['theme'] ) && isset( self::$themes[ $hook_extra['theme'] ] ) ) {
                return (string) $hook_extra['theme'];
            }
            if ( ! empty( $hook_extra['plugin'] ) && isset( self::$plugins[ $hook_extra['plugin'] ] ) ) {
                return dirname( $hook_extra['plugin'] );
            }

            return '';
        }

        /**
         * @return array<string,string>
         */
        private static function headers( string $accept = 'application/vnd.github+json' ): array {
            $headers = [
                'Accept'               => $accept,
                'X-GitHub-Api-Version' => '2022-11-28',
            ];
            if ( defined( 'ASTERISK_GITHUB_TOKEN' ) && ASTERISK_GITHUB_TOKEN ) {
                $headers['Authorization'] = 'Bearer ' . ASTERISK_GITHUB_TOKEN;
            }

            return $headers;
        }
    }
}
