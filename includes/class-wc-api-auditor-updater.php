<?php
/**
 * GitHub updater for WP API Monitor.
 *
 * @package WC_API_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handle plugin updates from GitHub releases.
 */
class WC_API_Auditor_Updater {

    const REPOSITORY          = 'geodefender/wp-api-monitor';
    const RELEASE_TRANSIENT   = 'wc_api_auditor_github_release';
    const CACHE_TTL           = HOUR_IN_SECONDS;
    const GITHUB_API_ENDPOINT = 'https://api.github.com/repos/%s/releases/latest';

    /**
     * Singleton instance.
     *
     * @var WC_API_Auditor_Updater|null
     */
    private static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return WC_API_Auditor_Updater
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register hooks.
     */
    public function init() {
        add_filter( 'site_transient_update_plugins', array( $this, 'maybe_set_update' ) );
        add_filter( 'plugins_api', array( $this, 'inject_plugin_info' ), 10, 3 );
    }

    /**
     * Inject update data into the plugins transient when a newer release exists.
     *
     * @param stdClass $transient Plugins update transient.
     *
     * @return stdClass
     */
    public function maybe_set_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( empty( $release['version'] ) || empty( $release['download_url'] ) ) {
            return $transient;
        }

        $current_version = WC_API_AUDITOR_VERSION;
        $release_version = ltrim( $release['version'], 'v' );

        if ( version_compare( $release_version, $current_version, '<=' ) ) {
            return $transient;
        }

        $plugin_basename = $this->get_plugin_basename();

        $transient->response[ $plugin_basename ] = (object) array(
            'slug'        => 'woocommerce-api-auditor',
            'plugin'      => $plugin_basename,
            'new_version' => $release_version,
            'package'     => $release['download_url'],
            'url'         => 'https://github.com/' . self::REPOSITORY,
        );

        return $transient;
    }

    /**
     * Provide plugin information for the Updates UI.
     *
     * @param mixed  $result The result object or WP_Error.
     * @param string $action The type of information being requested.
     * @param object $args   Plugin API arguments.
     *
     * @return mixed
     */
    public function inject_plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || empty( $args->slug ) || 'woocommerce-api-auditor' !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( empty( $release['version'] ) || empty( $release['download_url'] ) ) {
            return $result;
        }

        $plugin_info = (object) array(
            'name'          => 'WP API Monitor',
            'slug'          => 'woocommerce-api-auditor',
            'version'       => ltrim( $release['version'], 'v' ),
            'author'        => '<a href="https://github.com/geodefender">geodefender</a>',
            'homepage'      => 'https://github.com/' . self::REPOSITORY,
            'download_link' => $release['download_url'],
            'sections'      => array(
                'description' => ! empty( $release['notes'] ) ? wp_kses_post( $release['notes'] ) : '',
                'changelog'   => ! empty( $release['notes'] ) ? wp_kses_post( $release['notes'] ) : '',
            ),
        );

        return $plugin_info;
    }

    /**
     * Fetch and cache latest GitHub release data.
     *
     * @return array{version?:string,download_url?:string,notes?:string}
     */
    private function get_latest_release() {
        $cached = get_transient( self::RELEASE_TRANSIENT );

        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $endpoint = sprintf( self::GITHUB_API_ENDPOINT, self::REPOSITORY );
        $headers  = array(
            'Accept'     => 'application/vnd.github+json',
            'User-Agent' => 'wc-api-auditor-updater',
        );

        $token = $this->get_token();

        if ( '' !== $token ) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $response = wp_remote_get(
            $endpoint,
            array(
                'headers' => $headers,
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            return array();
        }

        $body    = wp_remote_retrieve_body( $response );
        $payload = json_decode( $body, true );

        if ( ! is_array( $payload ) ) {
            return array();
        }

        $download_url = '';

        if ( ! empty( $payload['assets'] ) && is_array( $payload['assets'] ) ) {
            $first_asset = reset( $payload['assets'] );
            if ( isset( $first_asset['browser_download_url'] ) ) {
                $download_url = esc_url_raw( $first_asset['browser_download_url'] );
            }
        }

        if ( '' === $download_url && ! empty( $payload['zipball_url'] ) ) {
            $download_url = esc_url_raw( $payload['zipball_url'] );
        }

        $release = array(
            'version'      => isset( $payload['tag_name'] ) ? sanitize_text_field( $payload['tag_name'] ) : '',
            'download_url' => $download_url,
            'notes'        => isset( $payload['body'] ) ? wp_kses_post( $payload['body'] ) : '',
        );

        set_transient( self::RELEASE_TRANSIENT, $release, self::CACHE_TTL );

        return $release;
    }

    /**
     * Download and install the latest GitHub release immediately.
     *
     * @return array{version?:string}|WP_Error
     */
    public function install_latest_release() {
        delete_transient( self::RELEASE_TRANSIENT );

        $release      = $this->get_latest_release();
        $download_url = isset( $release['download_url'] ) ? esc_url_raw( $release['download_url'] ) : '';

        if ( '' === $download_url ) {
            return new WP_Error( 'wc_api_auditor_no_package', __( 'No se pudo localizar el paquete de descarga en GitHub.', 'wc-api-auditor' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $tmp_file = download_url( $download_url, 120 );

        if ( is_wp_error( $tmp_file ) ) {
            return new WP_Error( 'wc_api_auditor_download_failed', __( 'No se pudo descargar la última release desde GitHub.', 'wc-api-auditor' ), $tmp_file );
        }

        $working_dir = trailingslashit( dirname( $tmp_file ) ) . 'wc-api-auditor-' . wp_generate_password( 8, false );

        if ( ! wp_mkdir_p( $working_dir ) ) {
            @unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return new WP_Error( 'wc_api_auditor_tmp_dir', __( 'No se pudo crear el directorio temporal para la actualización.', 'wc-api-auditor' ) );
        }

        $unzipped = unzip_file( $tmp_file, $working_dir );

        if ( is_wp_error( $unzipped ) ) {
            $this->cleanup_update_artifacts( $tmp_file, $working_dir );
            return new WP_Error( 'wc_api_auditor_unzip_failed', __( 'No se pudo descomprimir el paquete descargado.', 'wc-api-auditor' ), $unzipped );
        }

        $source_dirs = glob( trailingslashit( $working_dir ) . '*' );
        $source      = '';

        if ( ! empty( $source_dirs ) ) {
            foreach ( $source_dirs as $dir ) {
                if ( is_dir( $dir ) ) {
                    $source = $dir;
                    break;
                }
            }
        }

        if ( '' === $source ) {
            $this->cleanup_update_artifacts( $tmp_file, $working_dir );
            return new WP_Error( 'wc_api_auditor_source_missing', __( 'No se encontró el contenido del plugin en el paquete.', 'wc-api-auditor' ) );
        }

        global $wp_filesystem;

        if ( ! $wp_filesystem ) {
            WP_Filesystem();
        }

        if ( ! $wp_filesystem ) {
            $this->cleanup_update_artifacts( $tmp_file, $working_dir );
            return new WP_Error( 'wc_api_auditor_filesystem', __( 'No se pudo inicializar el sistema de archivos de WordPress.', 'wc-api-auditor' ) );
        }

        $plugin_file = $this->get_plugin_basename();
        $was_active  = is_plugin_active( $plugin_file );

        if ( $was_active ) {
            deactivate_plugins( $plugin_file, true );
        }

        $destination = untrailingslashit( WC_API_AUDITOR_PATH );
        $copied      = copy_dir( $source, $destination );

        if ( is_wp_error( $copied ) ) {
            if ( $was_active ) {
                activate_plugin( $plugin_file );
            }

            $this->cleanup_update_artifacts( $tmp_file, $working_dir );

            return new WP_Error( 'wc_api_auditor_copy_failed', __( 'No se pudieron copiar los archivos de la actualización.', 'wc-api-auditor' ), $copied );
        }

        if ( $was_active ) {
            $activation_result = activate_plugin( $plugin_file );

            if ( is_wp_error( $activation_result ) ) {
                $this->cleanup_update_artifacts( $tmp_file, $working_dir );
                return new WP_Error( 'wc_api_auditor_activation_failed', __( 'Los archivos se copiaron, pero no fue posible reactivar el plugin.', 'wc-api-auditor' ), $activation_result );
            }
        }

        $this->cleanup_update_artifacts( $tmp_file, $working_dir );

        return array(
            'version' => isset( $release['version'] ) ? ltrim( sanitize_text_field( $release['version'] ), 'v' ) : '',
        );
    }

    /**
     * Remove temporary files created during the manual update.
     *
     * @param string $tmp_file    Temporary zip file path.
     * @param string $working_dir Temporary directory path.
     */
    private function cleanup_update_artifacts( $tmp_file, $working_dir ) {
        global $wp_filesystem;

        if ( $wp_filesystem ) {
            $wp_filesystem->delete( $working_dir, true );
            $wp_filesystem->delete( $tmp_file );
            return;
        }

        if ( file_exists( $tmp_file ) ) {
            @unlink( $tmp_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }

        if ( file_exists( $working_dir ) ) {
            foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $working_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST ) as $path ) {
                if ( $path->isDir() ) {
                    @rmdir( $path->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                } else {
                    @unlink( $path->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
            }

            @rmdir( $working_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
    }

    /**
     * Retrieve stored GitHub token.
     *
     * @return string
     */
    private function get_token() {
        return defined( 'WC_API_AUDITOR_GITHUB_TOKEN' ) ? sanitize_text_field( WC_API_AUDITOR_GITHUB_TOKEN ) : '';
    }

    /**
     * Get plugin basename.
     *
     * @return string
     */
    private function get_plugin_basename() {
        return plugin_basename( WC_API_AUDITOR_PATH . 'woocommerce-api-auditor.php' );
    }
}
