<?php
/**
 * Logger for WP API Monitor.
 *
 * @package WC_API_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Captures WooCommerce REST API requests and responses.
 */
class WC_API_Auditor_Logger {

    const RAW_BODY_MAX_LENGTH = 10000;
    const DEFAULT_STORAGE_LIMIT = 51200; // 50 KB.
    const HASH_SAMPLE_MAX_LENGTH = 4096;

    /**
     * Singleton instance.
     *
     * @var WC_API_Auditor_Logger
     */
    private static $instance;

    /**
     * Temporary storage for request information.
     *
     * @var array
     */
    private $requests = array();

    /**
     * Track completed requests to avoid duplicate entries.
     *
     * @var array
     */
    private $completed_requests = array();

    /**
     * Cached settings.
     *
     * @var array
     */
    private $settings = null;

    /**
     * Tracks routes removed from the REST API during the last filter call.
     *
     * @var array
     */
    private $last_blocked_routes = array();

    /**
     * Get singleton instance.
     *
     * @return WC_API_Auditor_Logger
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
        add_filter( 'rest_request_before_callbacks', array( $this, 'capture_request' ), 5, 3 );
        add_filter( 'rest_request_after_callbacks', array( $this, 'capture_response' ), 20, 3 );
        add_filter( 'rest_post_dispatch', array( $this, 'capture_post_dispatch' ), 20, 3 );
        add_action( 'woocommerce_api_request', array( $this, 'capture_legacy_request' ), 10, 2 );
        add_filter( 'rest_endpoints', array( $this, 'filter_rest_endpoints' ), 10, 1 );
        add_action( 'wc_api_auditor_cleanup', array( $this, 'run_retention_cleanup' ) );
        add_action( 'init', array( $this, 'maybe_schedule_cleanup' ) );
    }

    /**
     * Capture legacy WooCommerce API requests.
     *
     * @param string $api      API name.
     * @param string $endpoint Endpoint.
     */
    public function capture_legacy_request( $api, $endpoint ) {
        $request_key = wp_generate_password( 12, false );

        $endpoint_path = sanitize_text_field( $endpoint );

        if ( ! empty( $_SERVER['QUERY_STRING'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $endpoint_path .= '?' . wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        $this->requests[ $request_key ] = array(
            'timestamp'      => current_time( 'mysql' ),
            'http_method'    => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
            'endpoint'       => $endpoint_path,
            'request_payload'=> wp_json_encode( $this->get_request_payload() ),
            'api_key_id'     => $this->detect_api_key_id(),
            'api_key_display'=> $this->detect_api_key_display(),
            'ip_address'     => $this->get_client_ip(),
            'user_agent'     => $this->get_user_agent(),
        );

        $this->requests[ $request_key ] = array_merge(
            $this->requests[ $request_key ],
            $this->get_ip_enrichment_fields( $this->requests[ $request_key ]['ip_address'] )
        );

        $this->insert_log( $this->requests[ $request_key ], 200, wp_json_encode( array( 'api' => $api ) ) );
    }

    /**
     * Capture request information before callbacks.
     *
     * @param mixed           $result  Response to be returned if already handled.
     * @param array           $handler Handler data.
     * @param WP_REST_Request $request Request object.
     *
     * @return mixed
     */
    public function capture_request( $result, $handler, $request ) {
        if ( ! $this->is_woocommerce_request( $request ) ) {
            return $result;
        }

        $request_key = spl_object_hash( $request );

        $this->requests[ $request_key ] = $this->build_payload( $request );

        return $result;
    }

    /**
     * Capture response after callbacks.
     *
     * @param WP_REST_Response|mixed $response Response.
     * @param array                  $handler  Handler info.
     * @param WP_REST_Request        $request  Request.
     *
     * @return mixed
     */
    public function capture_response( $response, $handler, $request ) {
        if ( ! $this->is_woocommerce_request( $request ) ) {
            return $response;
        }

        $request_key = spl_object_hash( $request );
        if ( ! isset( $this->requests[ $request_key ] ) ) {
            return $response;
        }

        $status      = 0;
        $body        = null;
        $rest_output = rest_ensure_response( $response );

        if ( $rest_output instanceof WP_REST_Response ) {
            $status = $rest_output->get_status();
            $body   = wp_json_encode( $rest_output->get_data() );
        } else {
            $status = 500;
            $body   = wp_json_encode( $rest_output );
        }

        $payload = $this->requests[ $request_key ];
        $this->insert_log( $payload, $status, $body );
        $this->completed_requests[ $request_key ] = true;
        unset( $this->requests[ $request_key ] );

        return $response;
    }

    /**
     * Capture responses generated before callbacks are executed.
     *
     * @param WP_REST_Response|mixed $response Response.
     * @param WP_REST_Server         $server   Server instance.
     * @param WP_REST_Request        $request  Request.
     *
     * @return mixed
     */
    public function capture_post_dispatch( $response, $server, $request ) {
        if ( ! $this->is_extended_capture_enabled() ) {
            return $response;
        }

        if ( ! $request instanceof WP_REST_Request ) {
            return $response;
        }

        if ( ! $this->is_woocommerce_request( $request ) ) {
            return $response;
        }

        $request_key = spl_object_hash( $request );

        if ( isset( $this->completed_requests[ $request_key ] ) ) {
            return $response;
        }

        $payload = isset( $this->requests[ $request_key ] ) ? $this->requests[ $request_key ] : $this->build_payload( $request );

        $status      = 0;
        $body        = null;
        $rest_output = rest_ensure_response( $response );

        if ( $rest_output instanceof WP_REST_Response ) {
            $status = $rest_output->get_status();
            $body   = wp_json_encode( $rest_output->get_data() );
        } else {
            $status = 500;
            $body   = wp_json_encode( $rest_output );
        }

        $this->insert_log( $payload, $status, $body );
        $this->completed_requests[ $request_key ] = true;

        return $response;
    }

    /**
     * Insert log into database.
     *
     * @param array  $payload       Stored request payload.
     * @param int    $response_code Response HTTP code.
     * @param string $response_body Response body JSON.
     */
    private function insert_log( $payload, $response_code, $response_body ) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'wc_api_audit_log';

        $storage_limit = $this->get_storage_limit();

        $request_payload = $this->prepare_content_for_storage( $payload['request_payload'], $storage_limit );
        $response_body   = $this->prepare_content_for_storage( $response_body, $storage_limit );

        $wpdb->insert(
            $table_name,
            array(
                'timestamp'       => $payload['timestamp'],
                'ip_address'      => $payload['ip_address'],
                'ip_country'      => isset( $payload['ip_country'] ) ? $payload['ip_country'] : '',
                'ip_city'         => isset( $payload['ip_city'] ) ? $payload['ip_city'] : '',
                'ip_organization' => isset( $payload['ip_organization'] ) ? $payload['ip_organization'] : '',
                'ip_lookup_message' => isset( $payload['ip_lookup_message'] ) ? $payload['ip_lookup_message'] : '',
                'user_agent'      => $payload['user_agent'],
                'http_method'     => $payload['http_method'],
                'endpoint'        => $payload['endpoint'],
                'api_key_id'      => $payload['api_key_id'],
                'api_key_display' => $payload['api_key_display'],
                'request_payload' => $request_payload,
                'raw_body'        => isset( $payload['raw_body'] ) ? $payload['raw_body'] : '',
                'response_code'   => absint( $response_code ),
                'response_body'   => $response_body,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
        );
    }

    /**
     * Determine if request belongs to WooCommerce namespace.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return bool
     */
    private function is_woocommerce_request( $request ) {
        $route = $request->get_route();
        $settings = $this->get_settings();

        $blocked_patterns = $this->get_blocked_patterns_from_settings( $settings );

        if ( $this->is_route_blocked( $route, $blocked_patterns ) ) {
            return false;
        }

        if ( ! empty( $settings['capture_all'] ) ) {
            return true;
        }

        $namespaces = array( '/wc/' );

        if ( ! empty( $settings['capture_extended'] ) && ! empty( $settings['extra_namespaces'] ) ) {
            foreach ( $settings['extra_namespaces'] as $namespace ) {
                $normalized = '/' . ltrim( $namespace, '/' );
                if ( '/' === $normalized ) {
                    continue;
                }

                $namespaces[] = untrailingslashit( $normalized ) . '/';
            }
        }

        foreach ( $namespaces as $namespace ) {
            if ( 0 === strpos( trailingslashit( $route ), $namespace ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build base payload for a request.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return array
     */
    private function build_payload( $request ) {
        $ip_address = $this->get_client_ip();

        $payload = array(
            'timestamp'       => current_time( 'mysql' ),
            'http_method'     => $request->get_method(),
            'endpoint'        => $this->get_endpoint_path( $request ),
            'request_payload' => wp_json_encode( $this->get_request_payload( $request ) ),
            'api_key_id'      => $this->detect_api_key_id(),
            'api_key_display' => $this->detect_api_key_display(),
            'ip_address'      => $ip_address,
            'user_agent'      => $this->get_user_agent( $request ),
        );

        return array_merge( $payload, $this->get_ip_enrichment_fields( $ip_address ) );
    }

    /**
     * Get normalized endpoint path.
     *
     * @param WP_REST_Request $request Request.
     *
     * @return string
     */
    private function get_endpoint_path( $request ) {
        $route = $request->get_route();
        $endpoint = trailingslashit( $route );

        if ( ! empty( $_SERVER['QUERY_STRING'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $endpoint = $endpoint . '?' . wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        }

        return $endpoint;
    }

    /**
     * Build request payload array.
     *
     * @param WP_REST_Request|null $request Request.
     *
     * @return array
     */
    private function get_request_payload( $request = null ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return array(
                'params' => array(),
                'body'   => '',
                'headers'=> array(),
                'raw_body' => '',
                'raw_body_truncated' => false,
            );
        }

        $body_params = $request->get_body_params();
        $json_params = $request->get_json_params();
        $headers     = $request->get_headers();
        $raw_body    = $this->prepare_raw_body( $request->get_body() );

        if ( ! empty( $json_params ) ) {
            $body_params = $json_params;
        }

        $sensitive_headers = array(
            'authorization',
            'proxy-authorization',
            'cookie',
            'set-cookie',
            'x-api-key',
            'x-woocommerce-signature',
            'x-wc-webhook-signature',
            'php-auth-pw',
        );

        $safe_headers = array();
        foreach ( $headers as $key => $value ) {
            $normalized_key = sanitize_key( $key );

            if ( in_array( $normalized_key, $sensitive_headers, true ) ) {
                $safe_headers[ $normalized_key ] = array( '[redacted]' );
                continue;
            }

            $safe_headers[ $normalized_key ] = array_map( 'sanitize_text_field', (array) $value );
        }

        return array(
            'params'  => $this->sanitize_recursive( $request->get_params() ),
            'body'    => $this->sanitize_recursive( $body_params ),
            'headers' => $safe_headers,
            'raw_body' => $raw_body['content'],
            'raw_body_truncated' => $raw_body['truncated'],
        );
    }

    /**
     * Prepare raw request body for storage.
     *
     * @param mixed $raw_body Raw body content.
     *
     * @return array
     */
    private function prepare_raw_body( $raw_body ) {
        $content   = '';
        $truncated = false;

        if ( ! is_string( $raw_body ) ) {
            return array(
                'content'   => $content,
                'truncated' => $truncated,
            );
        }

        $sanitized = wp_check_invalid_utf8( $raw_body );
        if ( null === $sanitized ) {
            $sanitized = '';
        }

        $max_length = $this->get_raw_body_max_length();
        $length     = function_exists( 'mb_strlen' ) ? mb_strlen( $sanitized ) : strlen( $sanitized );

        if ( $length > $max_length ) {
            $truncated = true;
            $sanitized = function_exists( 'mb_substr' ) ? mb_substr( $sanitized, 0, $max_length ) : substr( $sanitized, 0, $max_length );
        }

        return array(
            'content'   => $sanitized,
            'truncated' => $truncated,
        );
    }

    /**
     * Prepare large contents for storage, truncating and annotating when needed.
     *
     * @param mixed $content Original content.
     * @param int   $limit   Maximum characters to store.
     *
     * @return string
     */
    private function prepare_content_for_storage( $content, $limit ) {
        if ( ! is_string( $content ) ) {
            $content = wp_json_encode( $content );
        }

        if ( null === $content ) {
            $content = '';
        }

        $length = $this->get_string_length( $content );

        if ( $length <= $limit ) {
            return $content;
        }

        $hash_sample_length = $this->get_hash_sample_length( $limit );
        $hash_source        = $this->truncate_string( $content, $hash_sample_length );
        $hash               = hash( 'sha256', $hash_source );
        $truncated          = $this->truncate_string( $content, $limit );
        $marker             = '[TRUNCADO]';
        $annotation         = sprintf(
            '\n\n%s Hash SHA256 (parcial): %s. Longitud original: %d caracteres. Hash calculado sobre los primeros %d caracteres.',
            $marker,
            $hash,
            $length,
            $this->get_string_length( $hash_source )
        );

        return $truncated . $annotation;
    }

    /**
     * Get multibyte-safe string length.
     *
     * @param string $string String to measure.
     *
     * @return int
     */
    private function get_string_length( $string ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( $string ) : strlen( $string );
    }

    /**
     * Truncate a string safely.
     *
     * @param string $string String to truncate.
     * @param int    $limit  Maximum length.
     *
     * @return string
     */
    private function truncate_string( $string, $limit ) {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $string, 0, $limit );
        }

        return substr( $string, 0, $limit );
    }

    /**
     * Determine the maximum length of content to hash when truncating.
     *
     * @param int $storage_limit Configured storage limit.
     *
     * @return int
     */
    private function get_hash_sample_length( $storage_limit ) {
        $safe_limit = max(
            1,
            function_exists( 'absint' ) ? absint( $storage_limit ) : abs( (int) $storage_limit )
        );

        return min( $safe_limit, self::HASH_SAMPLE_MAX_LENGTH );
    }

    /**
     * Detect API key ID from request.
     *
     * @return int|null
     */
    private function detect_api_key_id() {
        $consumer_key = $this->detect_consumer_key();

        if ( empty( $consumer_key ) ) {
            return null;
        }

        if ( ! function_exists( 'wc_api_hash' ) ) {
            return null;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $hash  = wc_api_hash( $consumer_key );

        $key_row = $wpdb->get_row( $wpdb->prepare( "SELECT key_id FROM {$table} WHERE consumer_key = %s", $hash ) );
        if ( $key_row && isset( $key_row->key_id ) ) {
            return (int) $key_row->key_id;
        }

        return null;
    }

    /**
     * Detect API key for display, masking sensitive parts.
     *
     * @return string|null
     */
    private function detect_api_key_display() {
        $consumer_key = $this->detect_consumer_key();

        if ( empty( $consumer_key ) ) {
            return null;
        }

        return $this->mask_key( $consumer_key );
    }

    /**
     * Detect consumer key from headers or query params.
     *
     * @return string|null
     */
    private function detect_consumer_key() {
        $consumer_key = null;

        if ( isset( $_GET['consumer_key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $consumer_key = sanitize_text_field( wp_unslash( $_GET['consumer_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ( ! empty( $consumer_key ) ) {
            return $consumer_key;
        }

        $auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : '';
        if ( empty( $auth_header ) && function_exists( 'apache_request_headers' ) ) {
            $headers = apache_request_headers();
            if ( isset( $headers['Authorization'] ) ) {
                $auth_header = $headers['Authorization'];
            }
        }

        if ( ! empty( $auth_header ) && preg_match( '/Basic\s+(.*)$/i', $auth_header, $matches ) ) {
            $decoded = base64_decode( trim( $matches[1] ) );
            if ( $decoded && false !== strpos( $decoded, ':' ) ) {
                list( $key ) = explode( ':', $decoded );
                $consumer_key = sanitize_text_field( $key );
            }
        }

        return $consumer_key;
    }

    /**
     * Mask key for secure display.
     *
     * @param string $key Consumer key.
     *
     * @return string
     */
    private function mask_key( $key ) {
        $length = strlen( $key );
        if ( $length <= 8 ) {
            return str_repeat( '*', $length );
        }

        return substr( $key, 0, 6 ) . str_repeat( '*', max( 0, $length - 8 ) ) . substr( $key, -2 );
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip() {
        $keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );

        foreach ( $keys as $key ) {
            if ( isset( $_SERVER[ $key ] ) ) {
                $ip_list = explode( ',', wp_unslash( $_SERVER[ $key ] ) );
                $ip      = trim( $ip_list[0] );

                return sanitize_text_field( $ip );
            }
        }

        return '';
    }

    /**
     * Enrich IP information using a public provider with transient caching.
     *
     * @param string $ip_address IP address to enrich.
     *
     * @return array
     */
    private function get_ip_enrichment_fields( $ip_address ) {
        $defaults = array(
            'ip_country'         => '',
            'ip_city'            => '',
            'ip_organization'    => '',
            'ip_lookup_message'  => '',
        );

        $ip_address = sanitize_text_field( $ip_address );

        if ( '' === $ip_address ) {
            return $defaults;
        }

        $cache_key = 'wc_api_auditor_geo_' . md5( $ip_address );
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            return wp_parse_args( $cached, $defaults );
        }

        $data = $this->query_ip_provider( $ip_address );

        set_transient( $cache_key, $data, DAY_IN_SECONDS );

        return wp_parse_args( $data, $defaults );
    }

    /**
     * Query ip-api.com for IP enrichment.
     *
     * @param string $ip_address IP address.
     *
     * @return array
     */
    private function query_ip_provider( $ip_address ) {
        $result = array(
            'ip_country'         => '',
            'ip_city'            => '',
            'ip_organization'    => '',
            'ip_lookup_message'  => '',
        );

        $endpoint = sprintf(
            'http://ip-api.com/json/%s?fields=status,message,country,city,org',
            rawurlencode( $ip_address )
        );

        $response = wp_remote_get(
            esc_url_raw( $endpoint ),
            array(
                'timeout' => 5,
            )
        );

        if ( is_wp_error( $response ) ) {
            $error_message = sanitize_text_field( $response->get_error_message() );
            $result['ip_lookup_message'] = sprintf(
                /* translators: %s: WP_Error message */
                __( 'Enriquecimiento de IP falló: %s', 'wc-api-auditor' ),
                $error_message
            );

            return $result;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $status_code ) {
            $result['ip_lookup_message'] = sprintf(
                /* translators: %d: HTTP status code */
                __( 'Enriquecimiento de IP devolvió código %d.', 'wc-api-auditor' ),
                absint( $status_code )
            );

            return $result;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) ) {
            $result['ip_lookup_message'] = __( 'Respuesta de enriquecimiento de IP no válida.', 'wc-api-auditor' );

            return $result;
        }

        if ( isset( $body['status'] ) && 'success' === $body['status'] ) {
            $result['ip_country']      = isset( $body['country'] ) ? sanitize_text_field( $body['country'] ) : '';
            $result['ip_city']         = isset( $body['city'] ) ? sanitize_text_field( $body['city'] ) : '';
            $result['ip_organization'] = isset( $body['org'] ) ? sanitize_text_field( $body['org'] ) : '';

            return $result;
        }

        $message = isset( $body['message'] ) ? sanitize_text_field( $body['message'] ) : '';

        if ( '' !== $message ) {
            $result['ip_lookup_message'] = sprintf(
                /* translators: %s: error message from provider */
                __( 'Enriquecimiento de IP no disponible: %s', 'wc-api-auditor' ),
                $message
            );
        } else {
            $result['ip_lookup_message'] = __( 'No se pudo obtener información de IP.', 'wc-api-auditor' );
        }

        return $result;
    }

    /**
     * Retrieve the request user agent, preferring REST headers.
     *
     * @param WP_REST_Request|null $request Request object.
     *
     * @return string
     */
    private function get_user_agent( $request = null ) {
        if ( $request instanceof WP_REST_Request ) {
            $headers = $request->get_headers();

            if ( isset( $headers['user-agent'][0] ) ) {
                return sanitize_text_field( wp_unslash( $headers['user-agent'][0] ) );
            }
        }

        if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
        }

        return '';
    }

    /**
     * Recursively sanitize an array.
     *
     * @param mixed $value Value to sanitize.
     *
     * @return mixed
     */
    private function sanitize_recursive( $value ) {
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'sanitize_recursive' ), $value );
        }

        if ( is_scalar( $value ) ) {
            return sanitize_text_field( (string) $value );
        }

        return $value;
    }

    /**
     * Get the maximum raw body length before truncating.
     *
     * @return int
     */
    private function get_raw_body_max_length() {
        return self::RAW_BODY_MAX_LENGTH;
    }

    /**
     * Get maximum storage length for payloads and responses.
     *
     * @return int
     */
    private function get_storage_limit() {
        $settings = $this->get_settings();

        return isset( $settings['payload_max_length'] ) ? absint( $settings['payload_max_length'] ) : self::DEFAULT_STORAGE_LIMIT;
    }

    /**
     * Get logger settings.
     *
     * @return array
     */
    public function get_settings() {
        if ( null !== $this->settings ) {
            return $this->settings;
        }

        $defaults = self::get_default_settings();
        $stored   = get_option( 'wc_api_auditor_settings', array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $stored['capture_all']      = ! empty( $stored['capture_all'] );
        $stored['capture_extended'] = ! empty( $stored['capture_extended'] );
        $stored['extra_namespaces']   = $this->sanitize_namespaces_list( isset( $stored['extra_namespaces'] ) ? $stored['extra_namespaces'] : array() );
        $stored['payload_max_length'] = isset( $stored['payload_max_length'] ) ? absint( $stored['payload_max_length'] ) : self::DEFAULT_STORAGE_LIMIT;
        if ( $stored['payload_max_length'] <= 0 ) {
            $stored['payload_max_length'] = self::DEFAULT_STORAGE_LIMIT;
        }
        $stored['blocked_endpoints'] = $this->sanitize_blocked_endpoints_list( isset( $stored['blocked_endpoints'] ) ? $stored['blocked_endpoints'] : array() );
        $stored['blocked_endpoints_suggested'] = $this->sanitize_blocked_endpoints_list( isset( $stored['blocked_endpoints_suggested'] ) ? $stored['blocked_endpoints_suggested'] : array() );
        $stored['retention_days']         = isset( $stored['retention_days'] ) ? max( 0, absint( $stored['retention_days'] ) ) : 0;
        $stored['retention_max_records']  = isset( $stored['retention_max_records'] ) ? max( 0, absint( $stored['retention_max_records'] ) ) : 0;
        $stored['github_token']       = isset( $stored['github_token'] ) ? sanitize_text_field( $stored['github_token'] ) : '';

        $this->settings = wp_parse_args( $stored, $defaults );

        return $this->settings;
    }

    /**
     * Clear cached settings.
     */
    public function refresh_settings() {
        $this->settings = null;
        $this->last_blocked_routes = array();
    }

    /**
     * Default settings for the logger.
     *
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'capture_all'       => false,
            'capture_extended'  => false,
            'extra_namespaces'  => array(),
            'payload_max_length' => self::DEFAULT_STORAGE_LIMIT,
            'blocked_endpoints'  => array(),
            'blocked_endpoints_suggested' => array(),
            'retention_days'         => 0,
            'retention_max_records'  => 0,
            'github_token'       => '',
        );
    }

    /**
     * Normalize namespaces input.
     *
     * @param array|string $namespaces Namespaces input.
     *
     * @return array
     */
    public function sanitize_namespaces_list( $namespaces ) {
        if ( is_string( $namespaces ) ) {
            $namespaces = explode( ',', $namespaces );
        }

        if ( ! is_array( $namespaces ) ) {
            return array();
        }

        $clean = array();

        foreach ( $namespaces as $namespace ) {
            $namespace = sanitize_text_field( wp_strip_all_tags( $namespace ) );

            if ( '' === $namespace ) {
                continue;
            }

            $clean[] = untrailingslashit( '/' . ltrim( $namespace, '/' ) );
        }

        return array_values( array_unique( $clean ) );
    }

    /**
     * Normalize blocked endpoints input.
     *
     * @param array|string $endpoints Endpoints input.
     *
     * @return array
     */
    public function sanitize_blocked_endpoints_list( $endpoints ) {
        if ( is_string( $endpoints ) ) {
            $endpoints = preg_split( '/[\r\n,]+/', $endpoints );
        }

        if ( ! is_array( $endpoints ) ) {
            return array();
        }

        $clean = array();

        foreach ( $endpoints as $endpoint ) {
            $endpoint = sanitize_text_field( wp_strip_all_tags( $endpoint ) );

            if ( '' === $endpoint ) {
                continue;
            }

            $clean[] = $endpoint;
        }

        return array_values( array_unique( $clean ) );
    }

    /**
     * Combine blocked endpoint sources into a single sanitized list.
     *
     * @param array $settings Current settings.
     *
     * @return array
     */
    private function get_blocked_patterns_from_settings( $settings ) {
        $patterns = array();

        foreach ( array( 'blocked_endpoints', 'blocked_endpoints_suggested' ) as $key ) {
            if ( isset( $settings[ $key ] ) && is_array( $settings[ $key ] ) ) {
                $patterns = array_merge( $patterns, $settings[ $key ] );
            }
        }

        return $this->sanitize_blocked_endpoints_list( $patterns );
    }

    /**
     * Register cron job if not scheduled yet.
     */
    public function maybe_schedule_cleanup() {
        if ( ! wp_next_scheduled( 'wc_api_auditor_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'wc_api_auditor_cleanup' );
        }
    }

    /**
     * Apply retention rules to purge old or excess records.
     */
    public function run_retention_cleanup() {
        global $wpdb;

        $settings = $this->get_settings();
        $table    = $wpdb->prefix . 'wc_api_audit_log';

        $status = array(
            'last_run' => current_time( 'mysql' ),
            'deleted'  => 0,
            'policy'   => array(
                'retention_days'        => isset( $settings['retention_days'] ) ? absint( $settings['retention_days'] ) : 0,
                'retention_max_records' => isset( $settings['retention_max_records'] ) ? absint( $settings['retention_max_records'] ) : 0,
            ),
            'errors'   => array(),
            'result'   => 'skipped',
        );

        $retention_days        = $status['policy']['retention_days'];
        $retention_max_records = $status['policy']['retention_max_records'];

        if ( 0 === $retention_days && 0 === $retention_max_records ) {
            update_option( 'wc_api_auditor_cleanup_status', $status );
            return;
        }

        if ( $retention_days > 0 ) {
            $threshold = date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) - ( $retention_days * DAY_IN_SECONDS ) );
            $result    = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE timestamp < %s", $threshold ) );

            if ( false !== $result ) {
                $status['deleted'] += (int) $result;
            } else {
                $status['errors'][] = __( 'No se pudo limpiar por antigüedad.', 'wc-api-auditor' );
            }
        }

        if ( $retention_max_records > 0 ) {
            $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

            if ( $total > $retention_max_records ) {
                $offset  = $retention_max_records - 1;
                $cutoff  = $wpdb->get_var( $wpdb->prepare( "SELECT timestamp FROM {$table} ORDER BY timestamp DESC LIMIT 1 OFFSET %d", $offset ) );
                $deleted = 0;

                if ( $cutoff ) {
                    $deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE timestamp < %s", $cutoff ) );
                }

                if ( false !== $deleted ) {
                    $status['deleted'] += (int) $deleted;
                } else {
                    $status['errors'][] = __( 'No se pudo limpiar por límite de registros.', 'wc-api-auditor' );
                }
            }
        }

        if ( empty( $status['errors'] ) ) {
            $status['result'] = 'success';
        } else {
            $status['result'] = 'error';
        }

        update_option( 'wc_api_auditor_cleanup_status', $status );
    }

    /**
     * Determine if a REST route should be blocked.
     *
     * @param string $route     Route to test.
     * @param array  $patterns  Block list patterns.
     *
     * @return bool
     */
    private function is_route_blocked( $route, $patterns ) {
        if ( empty( $patterns ) || ! is_array( $patterns ) ) {
            return false;
        }

        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );

            if ( '' === $pattern ) {
                continue;
            }

            if ( 0 === strcasecmp( $route, $pattern ) ) {
                return true;
            }

            if ( strlen( $pattern ) >= 2 && '/*' === substr( $pattern, -2 ) ) {
                $prefix = rtrim( substr( $pattern, 0, -1 ), '/' ) . '/';

                if ( 0 === strncasecmp( $route, $prefix, strlen( $prefix ) ) || 0 === strcasecmp( rtrim( $route, '/' ), rtrim( $prefix, '/' ) ) ) {
                    return true;
                }
            }

            if ( false !== strpos( $pattern, '*' ) ) {
                $escaped_pattern = preg_quote( $pattern, '#' );
                $escaped_pattern = str_replace( '\*', '.*', $escaped_pattern );
                $regex           = '#^' . $escaped_pattern . '$#i';
            } else {
                $regex = '#^' . str_replace( '#', '\#', $pattern ) . '$#i';
            }

            $match = @preg_match( $regex, $route ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( false !== $match && $match > 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove blocked endpoints from the REST API index.
     *
     * @param array $endpoints Registered endpoints.
     *
     * @return array
     */
    public function filter_rest_endpoints( $endpoints ) {
        $settings = $this->get_settings();

        $blocked_patterns = $this->get_blocked_patterns_from_settings( $settings );

        if ( empty( $blocked_patterns ) ) {
            $this->last_blocked_routes = array();
            return $endpoints;
        }

        $blocked_routes = array();

        foreach ( $endpoints as $route => $handlers ) {
            if ( $this->is_route_blocked( $route, $blocked_patterns ) ) {
                unset( $endpoints[ $route ] );
                $blocked_routes[] = $route;
            }
        }

        $this->last_blocked_routes = $blocked_routes;

        return $endpoints;
    }

    /**
     * Get last blocked route list.
     *
     * @return array
     */
    public function get_last_blocked_routes() {
        return $this->last_blocked_routes;
    }

    /**
     * Check if extended capture is enabled.
     *
     * @return bool
     */
    private function is_extended_capture_enabled() {
        $settings = $this->get_settings();

        return ! empty( $settings['capture_extended'] );
    }
}
