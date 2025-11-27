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
        add_action( 'admin_post_wc_api_auditor_export', array( $this, 'handle_export' ) );
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

        $this->handle_export();
        $this->handle_delete_actions();
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
            <?php $this->render_blocked_notice( $settings ); ?>
            <?php $this->render_settings_form( $settings ); ?>
            <?php $this->render_filters( $filters ); ?>
            <?php $this->render_delete_all_form(); ?>
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
                        <th><?php esc_html_e( 'Acciones', 'wc-api-auditor' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $logs ) ) : ?>
                        <tr>
                            <td colspan="8"><?php esc_html_e( 'No hay registros para mostrar.', 'wc-api-auditor' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $logs as $log ) : ?>
                            <tr>
                                <td><?php echo esc_html( $log->timestamp ); ?></td>
                                <?php
                                $method       = strtoupper( $log->http_method );
                                $method_class = 'wc-api-auditor-method wc-api-auditor-method--' . sanitize_html_class( strtolower( $method ) );
                                ?>
                                <td><span class="<?php echo esc_attr( $method_class ); ?>"><?php echo esc_html( $method ); ?></span></td>
                                <td><?php echo esc_html( $log->endpoint ); ?></td>
                                <td><?php echo esc_html( $log->api_key_display ? $log->api_key_display : __( 'No detectada', 'wc-api-auditor' ) ); ?></td>
                                <td><?php echo esc_html( $log->ip_address ); ?></td>
                                <td><?php echo esc_html( $log->response_code ); ?></td>
                                <td>
                                    <button class="button" type="button" data-log-id="<?php echo esc_attr( $log->id ); ?>" onclick="wcApiAuditorToggle('<?php echo esc_js( $log->id ); ?>')">
                                        <?php esc_html_e( 'Ver detalle', 'wc-api-auditor' ); ?>
                                    </button>
                                </td>
                                <td>
                                    <form method="post" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar este registro?', 'wc-api-auditor' ) ); ?>');">
                                        <?php wp_nonce_field( 'wc_api_auditor_delete_action', 'wc_api_auditor_delete_nonce' ); ?>
                                        <input type="hidden" name="delete_log_id" value="<?php echo esc_attr( $log->id ); ?>" />
                                        <button class="button button-link-delete" type="submit"><?php esc_html_e( 'Eliminar', 'wc-api-auditor' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                            <tr id="wc-api-auditor-detail-<?php echo esc_attr( $log->id ); ?>" style="display:none;">
                                <td colspan="8">
                                    <?php $request_pre_id = 'wc-api-auditor-pre-' . $log->id . '-request'; ?>
                                    <strong><?php esc_html_e( 'Request', 'wc-api-auditor' ); ?>:</strong>
                                    <?php $this->render_json_pretty( 'Request', $log->request_payload, $request_pre_id ); ?>
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
                                        <?php $raw_body_pre_id = 'wc-api-auditor-pre-' . $log->id . '-raw-body'; ?>
                                        <strong><?php esc_html_e( 'Raw body', 'wc-api-auditor' ); ?>:</strong>
                                        <?php $this->render_json_pretty( 'Raw body', $raw_body, $raw_body_pre_id ); ?>
                                        <?php if ( $raw_body_truncated ) : ?>
                                            <em><?php esc_html_e( 'El cuerpo fue truncado para cumplir con el límite de almacenamiento.', 'wc-api-auditor' ); ?></em>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php $response_pre_id = 'wc-api-auditor-pre-' . $log->id . '-response'; ?>
                                    <strong><?php esc_html_e( 'Response', 'wc-api-auditor' ); ?>:</strong>
                                    <?php $this->render_json_pretty( 'Response', $log->response_body, $response_pre_id ); ?>
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

            document.addEventListener('DOMContentLoaded', function() {
                var copyButtons = document.querySelectorAll('.copy-json');
                var copiedLabel = '<?php echo esc_js( __( 'Copiado!', 'wc-api-auditor' ) ); ?>';

                copyButtons.forEach(function(button) {
                    button.addEventListener('click', function() {
                        var targetId = button.getAttribute('data-target');
                        var preElement = document.getElementById(targetId);

                        if (!preElement || !navigator.clipboard) {
                            return;
                        }

                        var originalText = button.textContent;
                        navigator.clipboard.writeText(preElement.innerText).then(function() {
                            button.textContent = copiedLabel;
                            button.disabled = true;

                            setTimeout(function() {
                                button.textContent = originalText;
                                button.disabled = false;
                            }, 1500);
                        });
                    });
                });
            });
        </script>
        <style type="text/css">
            .wc-api-auditor-method {
                display: inline-block;
                padding: 2px 8px;
                border-radius: 4px;
                font-weight: 600;
                font-size: 12px;
                background: #f1f5f9;
                color: #0f172a;
            }

            .wc-api-auditor-method--get {
                background: #e6f4ea;
                color: #0f5132;
            }

            .wc-api-auditor-method--post {
                background: #e0ecff;
                color: #1d4ed8;
            }

            .wc-api-auditor-method--delete {
                background: #fde2e1;
                color: #b91c1c;
            }

            .wc-api-auditor-method--put,
            .wc-api-auditor-method--patch,
            .wc-api-auditor-method--head,
            .wc-api-auditor-method--options,
            .wc-api-auditor-method--trace,
            .wc-api-auditor-method--connect,
            .wc-api-auditor-method--other {
                background: #f1f5f9;
                color: #0f172a;
            }

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

            .wc-api-highlight {
                background: #fce4ec;
                color: #c2185b;
                font-weight: 700;
                padding: 2px 4px;
                border-radius: 3px;
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
    private function render_json_pretty( $label, $raw_value, $pre_id = '' ) {
        $raw_value           = (string) $raw_value;
        $contains_truncation = ( false !== strpos( $raw_value, '[TRUNCADO]' ) );
        $decoded_value       = json_decode( $raw_value, true );
        $is_json             = ( JSON_ERROR_NONE === json_last_error() );
        $rendered_value      = $raw_value;

        if ( empty( $pre_id ) ) {
            $pre_id = 'wc-api-auditor-pre-' . wp_rand();
        }

        $pre_id = sanitize_key( $pre_id );

        if ( $is_json ) {
            $pretty_json = wp_json_encode( $decoded_value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

            if ( false !== $pretty_json ) {
                $rendered_value = $pretty_json;
            }
        }

        echo '<div class="wc-api-auditor-json">';
        echo '<div class="wc-api-auditor-json__actions">';
        echo '<button type="button" class="button copy-json" data-target="' . esc_attr( $pre_id ) . '">';
        esc_html_e( 'Copiar JSON', 'wc-api-auditor' );
        echo '</button>';
        echo '</div>';

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

        $rendered_value_display = esc_html( $rendered_value );

        if ( $is_json ) {
            $rendered_value_display = wp_kses(
                $this->highlight_json_keys( $rendered_value_display ),
                array(
                    'span' => array(
                        'class' => array(),
                    ),
                )
            );
        }

        echo '<pre id="' . esc_attr( $pre_id ) . '">' . $rendered_value_display . '</pre>';
        echo '</div>';
    }

    /**
     * Highlight specific header keys within pretty-printed JSON.
     *
     * @param string $escaped_json Escaped JSON string ready for display.
     *
     * @return string
     */
    private function highlight_json_keys( $escaped_json ) {
        $pattern = '/(&quot;)(authorization|x-wc-webhook-source|content-type|user-agent|x-signature|consumer_key)(&quot;\s*:)/i';
        $replace = '$1<span class="wc-api-highlight">$2</span>$3';

        return preg_replace( $pattern, $replace, $escaped_json );
    }

    /**
     * Render filter form.
     *
     * @param array $filters Current filters.
     */
    private function render_filters( $filters ) {
        ?>
        <form method="get" style="margin-bottom: 15px;" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="page" value="wc-api-auditor" />
            <input type="hidden" name="post_type" value="shop_order" />
            <input type="hidden" name="action" value="wc_api_auditor_export" />
            <?php wp_nonce_field( 'wc_api_auditor_export_action', 'wc_api_auditor_export_nonce', true ); ?>
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
            <button class="button" type="submit" name="export" value="csv"><?php esc_html_e( 'Exportar CSV', 'wc-api-auditor' ); ?></button>
        </form>
        <?php
    }

    /**
     * Render delete-all form.
     */
    private function render_delete_all_form() {
        ?>
        <form method="post" style="margin: 10px 0;" onsubmit="return confirm('<?php echo esc_js( __( '¿Eliminar todos los registros?', 'wc-api-auditor' ) ); ?>');">
            <?php wp_nonce_field( 'wc_api_auditor_delete_action', 'wc_api_auditor_delete_nonce' ); ?>
            <input type="hidden" name="delete_all_logs" value="1" />
            <button class="button button-secondary" type="submit"><?php esc_html_e( 'Borrar todo', 'wc-api-auditor' ); ?></button>
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
                <input type="checkbox" name="capture_all" value="1" <?php checked( $settings['capture_all'], true ); ?> />
                <?php esc_html_e( 'Capturar todas las solicitudes REST (todas las rutas)', 'wc-api-auditor' ); ?>
            </label>
            <p class="description"><?php esc_html_e( 'Al activarlo se registrará cualquier endpoint REST, no solo los de WooCommerce. Puede aumentar considerablemente el tamaño del log.', 'wc-api-auditor' ); ?></p>
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
            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e( 'Endpoints bloqueados (uno por línea)', 'wc-api-auditor' ); ?>
                <textarea name="blocked_endpoints" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", $settings['blocked_endpoints'] ) ); ?></textarea>
            </label>
            <p class="description"><?php esc_html_e( 'Cada entrada se compara de forma exacta (sin distinguir mayúsculas/minúsculas) contra la ruta registrada y como expresión regular con coincidencia completa. Se permiten comodines con * (por ejemplo /wp/v2/users/*) para bloquear rutas hijas. Ejemplos: /wp/v2/users, /wp/v2/users/* o /wp/v2/users(/(?P<id>[\\d]+))?.', 'wc-api-auditor' ); ?></p>
            <h2><?php esc_html_e( 'Retención y limpieza automática', 'wc-api-auditor' ); ?></h2>
            <p><?php esc_html_e( 'Configura cuántos días conservar los registros o establece un límite máximo de filas. La limpieza se ejecuta de forma automática mediante WP-Cron (dos veces al día) usando el índice por fecha para minimizar el impacto.', 'wc-api-auditor' ); ?></p>
            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e( 'Días de retención (0 para desactivar)', 'wc-api-auditor' ); ?>
                <input type="number" min="0" name="retention_days" value="<?php echo esc_attr( $settings['retention_days'] ); ?>" class="small-text" />
            </label>
            <label style="display: block; margin-bottom: 10px;">
                <?php esc_html_e( 'Límite máximo de registros (0 para desactivar)', 'wc-api-auditor' ); ?>
                <input type="number" min="0" name="retention_max_records" value="<?php echo esc_attr( $settings['retention_max_records'] ); ?>" class="small-text" />
            </label>
            <?php $this->render_cleanup_status(); ?>
            <button class="button button-primary" type="submit"><?php esc_html_e( 'Guardar ajustes', 'wc-api-auditor' ); ?></button>
        </form>
        <?php
    }

    /**
     * Render notice for blocked endpoints.
     *
     * @param array $settings Current settings.
     */
    private function render_blocked_notice( $settings ) {
        if ( empty( $settings['blocked_endpoints'] ) ) {
            return;
        }

        $logger          = WC_API_Auditor_Logger::get_instance();
        $blocked_routes  = $logger->get_last_blocked_routes();
        $blocked_message = esc_html__( 'Se están bloqueando endpoints REST según la configuración. Esto puede impedir acceso a ciertas rutas.', 'wc-api-auditor' );

        echo '<div class="notice notice-warning"><p>' . esc_html( $blocked_message ) . '</p>';

        if ( ! empty( $blocked_routes ) ) {
            echo '<p><strong>' . esc_html__( 'Rutas omitidas recientemente:', 'wc-api-auditor' ) . '</strong></p><ul>';
            foreach ( $blocked_routes as $route ) {
                echo '<li>' . esc_html( $route ) . '</li>';
            }
            echo '</ul>';
        } else {
            echo '<p><em>' . esc_html__( 'No se han registrado coincidencias aún, pero las rutas configuradas se eliminarán del índice REST si coinciden.', 'wc-api-auditor' ) . '</em></p>';
        }

        echo '</div>';
    }

    /**
     * Show last cleanup run info.
     */
    private function render_cleanup_status() {
        $status = get_option( 'wc_api_auditor_cleanup_status', array() );

        $last_run = isset( $status['last_run'] ) ? $status['last_run'] : __( 'Aún no se ha ejecutado.', 'wc-api-auditor' );
        $deleted  = isset( $status['deleted'] ) ? intval( $status['deleted'] ) : 0;
        $errors   = isset( $status['errors'] ) ? (array) $status['errors'] : array();

        echo '<div class="notice notice-info" style="padding: 15px;">';
        echo '<p><strong>' . esc_html__( 'Limpieza automática', 'wc-api-auditor' ) . '</strong></p>';
        echo '<p>' . sprintf( esc_html__( 'Última ejecución: %s.', 'wc-api-auditor' ), esc_html( $last_run ) ) . '</p>';
        echo '<p>' . sprintf( esc_html__( 'Registros eliminados en el último ciclo: %d.', 'wc-api-auditor' ), intval( $deleted ) ) . '</p>';

        $days        = isset( $status['policy']['retention_days'] ) ? intval( $status['policy']['retention_days'] ) : 0;
        $max_records = isset( $status['policy']['retention_max_records'] ) ? intval( $status['policy']['retention_max_records'] ) : 0;

        if ( 0 === $days && 0 === $max_records ) {
            echo '<p><em>' . esc_html__( 'La retención está desactivada. Define días o un máximo de registros para activar la limpieza automática.', 'wc-api-auditor' ) . '</em></p>';
        } else {
            echo '<p>' . esc_html__( 'Política activa:', 'wc-api-auditor' ) . ' ' . sprintf( esc_html__( '%1$s días de retención, máximo %2$s registros (0 = sin límite).', 'wc-api-auditor' ), intval( $days ), intval( $max_records ) ) . '</p>';
        }

        if ( ! empty( $errors ) ) {
            echo '<p class="description" style="color:#b91c1c;">' . esc_html( implode( ' ', $errors ) ) . '</p>';
        }

        echo '</div>';
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

        $capture_all      = isset( $_POST['capture_all'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $capture_extended = isset( $_POST['capture_extended'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $namespaces_raw   = isset( $_POST['extra_namespaces'] ) ? wp_unslash( $_POST['extra_namespaces'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $payload_limit_kb = isset( $_POST['payload_max_length'] ) ? intval( $_POST['payload_max_length'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $blocked_raw      = isset( $_POST['blocked_endpoints'] ) ? wp_unslash( $_POST['blocked_endpoints'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $retention_days   = isset( $_POST['retention_days'] ) ? intval( $_POST['retention_days'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $retention_limit  = isset( $_POST['retention_max_records'] ) ? intval( $_POST['retention_max_records'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        $logger    = WC_API_Auditor_Logger::get_instance();
        $settings  = array(
            'capture_all'      => (bool) $capture_all,
            'capture_extended' => (bool) $capture_extended,
            'extra_namespaces' => $logger->sanitize_namespaces_list( $namespaces_raw ),
            'payload_max_length' => max( 1024, $payload_limit_kb * 1024 ),
            'blocked_endpoints'  => $logger->sanitize_blocked_endpoints_list( $blocked_raw ),
            'retention_days'      => max( 0, $retention_days ),
            'retention_max_records' => max( 0, $retention_limit ),
        );

        update_option( 'wc_api_auditor_settings', $settings );
        $logger->refresh_settings();

        add_settings_error( 'wc_api_auditor_settings', 'wc_api_auditor_settings_saved', esc_html__( 'Ajustes guardados correctamente.', 'wc-api-auditor' ), 'updated' );
    }

    /**
     * Handle CSV export from filters.
     */
    public function handle_export() {
        $export = isset( $_GET['export'] ) ? sanitize_text_field( wp_unslash( $_GET['export'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( 'csv' !== $export ) {
            return;
        }

        $nonce = isset( $_GET['wc_api_auditor_export_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['wc_api_auditor_export_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( ! wp_verify_nonce( $nonce, 'wc_api_auditor_export_action' ) ) {
            wp_die( esc_html__( 'Nonce de exportación no válido.', 'wc-api-auditor' ) );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-api-auditor' ) );
        }

        $filters = $this->get_filters();
        $logs    = $this->get_all_logs( $filters );

        $this->export_logs_as_csv( $logs );

        exit;
    }

    /**
     * Handle delete actions (single or all logs).
     */
    private function handle_delete_actions() {
        if ( ! isset( $_POST['wc_api_auditor_delete_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_api_auditor_delete_nonce'] ) ), 'wc_api_auditor_delete_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'wc_api_audit_log';

        if ( isset( $_POST['delete_log_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $log_id = absint( $_POST['delete_log_id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

            if ( $log_id > 0 ) {
                $deleted = $wpdb->delete( $table, array( 'id' => $log_id ), array( '%d' ) );

                if ( false !== $deleted ) {
                    add_settings_error( 'wc_api_auditor_settings', 'wc_api_auditor_log_deleted', esc_html__( 'Registro eliminado correctamente.', 'wc-api-auditor' ), 'updated' );
                } else {
                    add_settings_error( 'wc_api_auditor_settings', 'wc_api_auditor_log_delete_failed', esc_html__( 'No se pudo eliminar el registro.', 'wc-api-auditor' ), 'error' );
                }
            }

            return;
        }

        if ( isset( $_POST['delete_all_logs'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $result = $wpdb->query( "TRUNCATE TABLE {$table}" );

            if ( false !== $result ) {
                add_settings_error( 'wc_api_auditor_settings', 'wc_api_auditor_logs_cleared', esc_html__( 'Todos los registros fueron eliminados.', 'wc-api-auditor' ), 'updated' );
            } else {
                add_settings_error( 'wc_api_auditor_settings', 'wc_api_auditor_logs_clear_failed', esc_html__( 'No se pudieron eliminar los registros.', 'wc-api-auditor' ), 'error' );
            }
        }
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
     * Get all logs matching filters without pagination.
     *
     * @param array $filters Filters.
     *
     * @return array
     */
    private function get_all_logs( $filters ) {
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

        $query = $wpdb->prepare( "SELECT * FROM {$table} {$sql_where} ORDER BY timestamp DESC", $values );

        return $wpdb->get_results( $query );
    }

    /**
     * Export logs as CSV output.
     *
     * @param array $logs Logs to export.
     */
    private function export_logs_as_csv( $logs ) {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=wc-api-logs.csv' );

        $output  = fopen( 'php://output', 'w' );
        $header = (object) array(
            'timestamp'       => 'timestamp',
            'http_method'     => 'method',
            'endpoint'        => 'endpoint',
            'api_key_display' => 'api_key_display',
            'ip_address'      => 'ip_address',
            'response_code'   => 'response_code',
            'request_payload' => 'request_payload',
            'response_body'   => 'response_body',
            'raw_body'        => 'raw_body',
        );

        fwrite( $output, $this->build_csv_line( $header ) );

        foreach ( $logs as $log ) {
            $row = (object) array(
                'timestamp'       => isset( $log->timestamp ) ? $log->timestamp : '',
                'http_method'     => isset( $log->http_method ) ? $log->http_method : '',
                'endpoint'        => isset( $log->endpoint ) ? $log->endpoint : '',
                'api_key_display' => isset( $log->api_key_display ) ? $log->api_key_display : '',
                'ip_address'      => isset( $log->ip_address ) ? $log->ip_address : '',
                'response_code'   => isset( $log->response_code ) ? $log->response_code : '',
                'request_payload' => isset( $log->request_payload ) ? $log->request_payload : '',
                'response_body'   => isset( $log->response_body ) ? $log->response_body : '',
                'raw_body'        => isset( $log->raw_body ) ? $log->raw_body : '',
            );

            fwrite( $output, $this->build_csv_line( $row ) );
        }

        fclose( $output );
    }

    /**
     * Build a single CSV line using RFC 4180 rules.
     *
     * @param object $row Log row data.
     *
     * @return string
     */
    private function build_csv_line( $row ) {
        return
            $this->csv_escape( $row->timestamp ) . ',' .
            $this->csv_escape( $row->http_method ) . ',' .
            $this->csv_escape( $row->endpoint ) . ',' .
            $this->csv_escape( $row->api_key_display ) . ',' .
            $this->csv_escape( $row->ip_address ) . ',' .
            $this->csv_escape( $row->response_code ) . ',' .
            $this->csv_escape( $row->request_payload ) . ',' .
            $this->csv_escape( $row->response_body ) . ',' .
            $this->csv_escape( $row->raw_body ) .
            "\n";
    }

    /**
     * Escape a single value for RFC 4180 compliant CSV.
     *
     * @param mixed $value Value to escape.
     *
     * @return string
     */
    private function csv_escape( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            $value = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
        }

        $value = (string) $value;
        $value = str_replace( '"', '""', $value );

        return '"' . $value . '"';
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
