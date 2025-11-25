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

        $filters = $this->get_filters();
        $logs    = $this->get_logs( $filters );
        $total   = $this->count_logs( $filters );
        $paged   = $filters['paged'];
        $per_page = $filters['per_page'];
        $total_pages = $per_page > 0 ? ceil( $total / $per_page ) : 1;

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__( 'WooCommerce API Auditor', 'wc-api-auditor' ); ?></h1>
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
                                    <pre><?php echo esc_html( $log->request_payload ); ?></pre>
                                    <strong><?php esc_html_e( 'Response', 'wc-api-auditor' ); ?>:</strong>
                                    <pre><?php echo esc_html( $log->response_body ); ?></pre>
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
        <?php
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
