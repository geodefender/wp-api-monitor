<?php
/**
 * Admin UI for WooCommerce API Auditor.
 *
 * @package WC_API_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class to render log table and filters.
 */
class WC_API_Auditor_Admin {

    /**
     * Singleton instance.
     *
     * @var WC_API_Auditor_Admin
     */
    private static $instance;

    /**
     * Get singleton instance.
     *
     * @return WC_API_Auditor_Admin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Register admin hooks.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
    }

    /**
     * Add submenu under WooCommerce.
     */
    public function register_menu() {
        add_submenu_page(
            'woocommerce',
            esc_html__( 'API Auditor', 'wc-api-auditor' ),
            esc_html__( 'API Auditor', 'wc-api-auditor' ),
            'manage_woocommerce',
            'wc-api-auditor',
            array( $this, 'render_page' )
        );
    }

    /**
     * Render admin page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-api-auditor' ) );
        }

        $this->handle_settings_form();

        $settings = $this->get_settings();
        $filters = $this->get_filters();
        $logs    = $this->get_logs( $filters );
        $total   = $this->count_logs( $filters );
        $paged   = $filters['paged'];
        $per_page = $filters['per_page'];
        $total_pages = $per_page > 0 ? ceil( $total / $per_page ) : 1;

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'WooCommerce API Auditor', 'wc-api-auditor' ); ?></h1>
            <?php settings_errors( 'wc_api_auditor_settings' ); ?>
            <?php $this->render_settings_form( $settings ); ?>
            <?php $this->render_filters( $filters ); ?>
            <p><?php printf( esc_html__( 'Mostrando %1$d de %2$d registros.', 'wc-api-auditor' ), count( $logs ), intval( $total ) ); ?></p>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Fecha', 'wc-api-auditor' ); ?></th>
                        <th><?php esc_html_e( 'Método', 'wc-api-auditor' ); ?></th>
                        <th><?php esc_html_e( 'Endpoint', 'wc-api-auditor' ); ?></th>
                        <th><?php esc_html_e( 'API Key', 'wc-api-auditor' ); ?></th>
                        <th><?php esc_html_e( 'IP', 'wc-api-auditor' ); ?></th>
                        <th><?php esc_html_e( 'Código', 'wc-api-auditor' ); ?></th>
                        <th><?php esc_html_e( 'Detalle', 'wc-api-auditor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'No hay registros para mostrar.', 'wc-api-auditor' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->timestamp ); ?></td>
                                <td><?php echo esc_html( strtoupper( $log->http_method ) ); ?></td>
                                <td><?php echo esc_html( $log->endpoint ); ?></td>
                                <td><?php echo esc_html( $log->api_key_display ? $log->api_key_display : __( 'No detectada', 'wc-api-auditor' ) ); ?></td>
                                <td><?php echo esc_html( $log->ip_address ); ?></td>
                                <td><?php echo esc_html( $log->response_code ); ?></td>
                                <td>
                                    <button class="button" type="button" data-log-id="<?php echo esc_attr( $log->id ); ?>" onclick="wcApiAuditorToggle('<?php echo esc_js( $log->id ); ?>')">
                                        <?php esc_html_e( 'Ver detalle', 'wc-api-auditor' ); ?>
                                    </button>
                                </td>
                            </tr>
                            <tr id="wc-api-auditor-detail-<?php echo esc_attr( $log->id ); ?>" style="display:none;">
                                <td colspan="7">
                                    <strong><?php esc_html_e( 'Request', 'wc-api-auditor' ); ?>:</strong>
                                    <?php $this->render_json_pretty( 'Request', $log->request_payload ); ?>
                                    <?php
                                    $raw_body            = '';
                                    $raw_body_truncated  = false;
                                    $decoded_payload     = json_decode( $log->request_payload, true );

                                    if ( is_array( $decoded_payload ) ) {
                                        if ( isset( $decoded_payload['raw_body'] ) ) {
                                            $raw_body = $decoded_payload['raw_body'];
                                        }

                                        if ( ! empty( $decoded_payload['raw_body_truncated'] ) ) {
                                            $raw_body_truncated = (bool) $decoded_payload['raw_body_truncated'];
                                        }
                                    }

                                    if ( isset( $log->raw_body ) && '' !== $log->raw_body ) {
                                        $raw_body = $log->raw_body;
                                    }

                                    if ( '' !== $raw_body ) :
                                    ?>
                                        <strong><?php esc_html_e( 'Raw body', 'wc-api-auditor' ); ?>:</strong>
                                        <?php $this->render_json_pretty( 'Raw body', $raw_body ); ?>
                                        <?php if ( $raw_body_truncated ) : ?>
                                            <em><?php esc_html_e( 'El cuerpo fue truncado para cumplir con el límite de almacenamiento.', 'wc-api-auditor' ); ?></em>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <strong><?php esc_html_e( 'Response', 'wc-api-auditor' ); ?>:</strong>
                                    <?php $this->render_json_pretty( 'Response', $log->response_body ); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php $this->render_pagination( $paged, $total_pages ); ?>
        </div>
        <script type="text/javascript">
            function wcApiAuditorToggle(id) {
                var row = document.getElementById('wc-api-auditor-detail-' + id);
                if (row.style.display === 'none') {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            }
        </script>
        <style type="text/css">
            .wc-api-auditor-json {
                background: #f7f7f7;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 4px;
                max-height: 400px;
                overflow-y: auto;
                font-family: monospace;
                font-size: 13px;
                white-space: pre-wrap;
                word-break: break-word;
            }

            .wc-api-auditor-json pre {
                margin: 0;
            }

            .wc-api-auditor-warning {
                color: #d63638;
                font-weight: 700;
                margin-bottom: 8px;
            }
        </style>
        <?php
    }

    /**
     * Render pretty JSON (or raw content) with optional truncation warning.
     *
     * @param string $label     Label to display in warning messages.
     * @param string $raw_value Raw value to render.
     */
    private function render_json_pretty( $label, $raw_value ) {
        $raw_value           = (string) $raw_value;
        $contains_truncation = ( false !== strpos( $raw_value, '[TRUNCADO]' ) );
        $decoded_value       = json_decode( $raw_value, true );
        $is_json             = ( JSON_ERROR_NONE === json_last_error() );
        $rendered_value      = $raw_value;

        if ( $is_json ) {
            $pretty_json = wp_json_encode( $decoded_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

            if ( false !== $pretty_json ) {
                $rendered_value = $pretty_json;
            }
        }

        echo '<div class="wc-api-auditor-json">';

        if ( $contains_truncation ) {
            printf(
                '<div class="wc-api-auditor-warning">%s</div>',
                sprintf(
                    /* translators: %s: payload label. */
                    esc_html__( '%s contiene datos truncados.', 'wc-api-auditor' ),
                    esc_html( $label )
                )
            );
        }

        echo '<pre>' . esc_html( $rendered_value ) . '</pre>';
        echo '</div>';
    }

    /**
     * Render filter form.
     *
     * @param array $filters Current filters.
     */
    private function render_filters( $filters ) {
        ?>
        <form method="get" style="margin-bottom: 15px;">
            <input type="hidden" name="page" value="wc-api-auditor" />
            <input type="hidden" name="post_type" value="shop_order" />
            <label>
                <?php esc_html_e( 'Desde', 'wc-api-auditor' ); ?>
                <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
            </label>
            <label>
                <?php esc_html_e( 'Hasta', 'wc-api-auditor' ); ?>
                <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
            </label>
            <label>
                <?php esc_html_e( 'Método', 'wc-api-auditor' ); ?>
                <select name="method">
                    <option value="">--</option>
                    <?php foreach ( array( 'GET', 'POST', 'PUT', 'DELETE', 'PATCH' ) as $method ) : ?>
                        <option value="<?php echo esc_attr( $method ); ?>" <?php selected( $filters['method'], $method ); ?>><?php echo esc_html( $method ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e( 'API Key', 'wc-api-auditor' ); ?>
                <input type="text" name="api_key" value="<?php echo esc_attr( $filters['api_key'] ); ?>" />
            </label>
            <label>
                <?php esc_html_e( 'Endpoint contiene', 'wc-api-auditor' ); ?>
                <input type="text" name="endpoint" value="<?php echo esc_attr( $filters['endpoint'] ); ?>" />
            </label>
            <button class="button button-primary" type="submit"><?php esc_html_e( 'Filtrar', 'wc-api-auditor' ); ?></button>
        </form>
        <?php
    }

    /**
     * Render settings form.
     *
     * @param array $settings Current settings.
     */
    private function render_settings_form( $settings ) {
        ?>
        <form method="post" style="margin-bottom: 20px;" action="<?php echo esc_url( admin_url( 'admin.php?page=wc-api-auditor' ) ); ?>">
            <?php wp_nonce_field( 'wc_api_auditor_settings_action', 'wc_api_auditor_settings_nonce' ); ?>
            <h2><?php esc_html_e( 'Ajustes de captura', 'wc-api-auditor' ); ?></h2>
            <p><?php esc_html_e( 'Activa la captura ampliada para registrar errores previos al callback y rutas adicionales. Esto puede generar un mayor volumen de datos.', 'wc-api-auditor' ); ?></p>
            <label style="display: block; margin-bottom: 10px;">
                <input type="checkbox" name="capture_extended" value="1" <?php checked( $settings['capture_extended'], true ); ?> />
                <?php esc_html_e( 'Activar captura ampliada', 'wc-api-auditor' ); ?>
            </label>
            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e( 'Namespaces adicionales (separados por coma)', 'wc-api-auditor' ); ?>
                <input type="text" name="extra_namespaces" value="<?php echo esc_attr( implode( ', ', $settings['extra_namespaces'] ) ); ?>" class="regular-text" />
            </label>
            <p class="description"><?php esc_html_e( 'Utiliza este ajuste si necesitas auditar otros endpoints (por ejemplo, /wp/v2/). Considera el impacto en el tamaño de la base de datos.', 'wc-api-auditor' ); ?></p>
            <?php $payload_limit_kb = max( 1, intval( ceil( $settings['payload_max_length'] / 1024 ) ) ); ?>
            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e( 'Límite de almacenamiento para cuerpos (KB)', 'wc-api-auditor' ); ?>
                <input type="number" min="1" name="payload_max_length" value="<?php echo esc_attr( $payload_limit_kb ); ?>" class="small-text" />
            </label>
            <p class="description"><?php esc_html_e( 'Los cuerpos de petición y respuesta que superen este límite se truncarán y se marcarán como [TRUNCADO], guardando un hash del contenido completo para trazabilidad. Valor por defecto: 50 KB.', 'wc-api-auditor' ); ?></p>
            <button class="button button-primary" type="submit"><?php esc_html_e( 'Guardar ajustes', 'wc-api-auditor' ); ?></button>
        </form>
        <?php
    }

    /**
     * Build query filters from request.
     *
     * @return array
     */
    private function get_filters() {
        $from     = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to       = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '';   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $method   = isset( $_GET['method'] ) ? sanitize_text_field( wp_unslash( $_GET['method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $api_key  = isset( $_GET['api_key'] ) ? sanitize_text_field( wp_unslash( $_GET['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $endpoint = isset( $_GET['endpoint'] ) ? sanitize_text_field( wp_unslash( $_GET['endpoint'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        return array(
            'from'     => $from,
            'to'       => $to,
            'method'   => $method,
            'api_key'  => $api_key,
            'endpoint' => $endpoint,
            'paged'    => $paged,
            'per_page' => 20,
        );
    }

    /**
     * Handle settings form submission.
     */
    private function handle_settings_form() {
        if ( ! isset( $_POST['wc_api_auditor_settings_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_api_auditor_settings_nonce'] ) ), 'wc_api_auditor_settings_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $capture_extended = isset( $_POST['capture_extended'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $namespaces_raw   = isset( $_POST['extra_namespaces'] ) ? wp_unslash( $_POST['extra_namespaces'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payload_limit_kb = isset( $_POST['payload_max_length'] ) ? intval( $_POST['payload_max_length'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $logger    = WC_API_Auditor_Logger::get_instance();
        $settings  = array(
            'capture_extended' => (bool) $capture_extended,
            'extra_namespaces' => $logger->sanitize_namespaces_list( $namespaces_raw ),
            'payload_max_length' => max( 1024, $payload_limit_kb * 1024 ),
        );

        update_option( 'wc_api_auditor_settings', $settings );
        $logger->refresh_settings();

        add_settings_error( 'wc_api_auditor_settings', 'wc_api_auditor_settings_saved', esc_html__( 'Ajustes guardados correctamente.', 'wc-api-auditor' ), 'updated' );
    }

    /**
     * Retrieve saved settings.
     *
     * @return array
     */
    private function get_settings() {
        return WC_API_Auditor_Logger::get_instance()->get_settings();
    }

    /**
     * Retrieve logs based on filters.
     *
     * @param array $filters Filters.
     *
     * @return array
     */
    private function get_logs( $filters ) {
        global $wpdb;

        $table      = $wpdb->prefix . 'wc_api_audit_log';
        $where      = array();
        $values     = array();
        $start_date = $filters['from'];
        $end_date   = $filters['to'];

        if ( ! empty( $start_date ) ) {
            $where[]  = 'timestamp >= %s';
            $values[] = $start_date . ' 00:00:00';
        }

        if ( ! empty( $end_date ) ) {
            $where[]  = 'timestamp <= %s';
            $values[] = $end_date . ' 23:59:59';
        }

        if ( ! empty( $filters['method'] ) ) {
            $where[]  = 'http_method = %s';
            $values[] = strtoupper( $filters['method'] );
        }

        if ( ! empty( $filters['api_key'] ) ) {
            $where[]  = '(api_key_display LIKE %s OR api_key_id = %d)';
            $values[] = '%' . $wpdb->esc_like( $filters['api_key'] ) . '%';
            $values[] = intval( $filters['api_key'] );
        }

        if ( ! empty( $filters['endpoint'] ) ) {
            $where[]  = 'endpoint LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['endpoint'] ) . '%';
        }

        $sql_where = '';
        if ( ! empty( $where ) ) {
            $sql_where = 'WHERE ' . implode( ' AND ', $where );
        }

        $offset = ( $filters['paged'] - 1 ) * $filters['per_page'];
        $limit  = $filters['per_page'];

        $query = $wpdb->prepare(
            "SELECT * FROM {$table} {$sql_where} ORDER BY timestamp DESC LIMIT %d, %d",
            array_merge( $values, array( $offset, $limit ) )
        );

        return $wpdb->get_results( $query );
    }

    /**
     * Count logs for pagination.
     *
     * @param array $filters Filters.
     *
     * @return int
     */
    private function count_logs( $filters ) {
        global $wpdb;

        $table  = $wpdb->prefix . 'wc_api_audit_log';
        $where  = array();
        $values = array();

        if ( ! empty( $filters['from'] ) ) {
            $where[]  = 'timestamp >= %s';
            $values[] = $filters['from'] . ' 00:00:00';
        }

        if ( ! empty( $filters['to'] ) ) {
            $where[]  = 'timestamp <= %s';
            $values[] = $filters['to'] . ' 23:59:59';
        }

        if ( ! empty( $filters['method'] ) ) {
            $where[]  = 'http_method = %s';
            $values[] = strtoupper( $filters['method'] );
        }

        if ( ! empty( $filters['api_key'] ) ) {
            $where[]  = '(api_key_display LIKE %s OR api_key_id = %d)';
            $values[] = '%' . $wpdb->esc_like( $filters['api_key'] ) . '%';
            $values[] = intval( $filters['api_key'] );
        }

        if ( ! empty( $filters['endpoint'] ) ) {
            $where[]  = 'endpoint LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $filters['endpoint'] ) . '%';
        }

        $sql_where = '';
        if ( ! empty( $where ) ) {
            $sql_where = 'WHERE ' . implode( ' AND ', $where );
        }

        $query = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$sql_where}", $values );

        return (int) $wpdb->get_var( $query );
    }

    /**
     * Render pagination links.
     *
     * @param int $current_page Current page.
     * @param int $total_pages  Total pages.
     */
    private function render_pagination( $current_page, $total_pages ) {
        if ( $total_pages <= 1 ) {
            return;
        }

        $base_url = remove_query_arg( 'paged' );
        echo '<div class="tablenav"><div class="tablenav-pages">';

        for ( $i = 1; $i <= $total_pages; $i++ ) {
            $url   = esc_url( add_query_arg( 'paged', $i, $base_url ) );
            $class = $i === $current_page ? ' class="page-numbers current"' : ' class="page-numbers"';
            printf( '<a%1$s href="%2$s">%3$d</a> ', $class, $url, intval( $i ) );
        }

        echo '</div></div>';
    }
}
