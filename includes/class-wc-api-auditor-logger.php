<?php
/**
 * Logger for WooCommerce API Auditor.
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
    }

    /**
     * Capture legacy WooCommerce API requests.
     *
     * @param string $api      API name.
     * @param string $endpoint Endpoint.
     */
    public function capture_legacy_request( $api, $endpoint ) {
        $request_key = wp_generate_password( 12, false );

        $this->requests[ $request_key ] = array(
            'timestamp'      => current_time( 'mysql' ),
            'http_method'    => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
            'endpoint'       => sanitize_text_field( $endpoint ),
            'request_payload'=> wp_json_encode( $this->get_request_payload() ),
            'api_key_id'     => $this->detect_api_key_id(),
            'api_key_display'=> $this->detect_api_key_display(),
            'ip_address'     => $this->get_client_ip(),
            'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
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

        $wpdb->insert(
            $table_name,
            array(
                'timestamp'       => $payload['timestamp'],
                'ip_address'      => $payload['ip_address'],
                'user_agent'      => $payload['user_agent'],
                'http_method'     => $payload['http_method'],
                'endpoint'        => $payload['endpoint'],
                'api_key_id'      => $payload['api_key_id'],
                'api_key_display' => $payload['api_key_display'],
                'request_payload' => $payload['request_payload'],
                'raw_body'        => isset( $payload['raw_body'] ) ? $payload['raw_body'] : '',
                'response_code'   => absint( $response_code ),
                'response_body'   => $response_body,
            ),
            array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
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
        return array(
            'timestamp'       => current_time( 'mysql' ),
            'http_method'     => $request->get_method(),
            'endpoint'        => $this->get_endpoint_path( $request ),
            'request_payload' => wp_json_encode( $this->get_request_payload( $request ) ),
            'api_key_id'      => $this->detect_api_key_id(),
            'api_key_display' => $this->detect_api_key_display(),
            'ip_address'      => $this->get_client_ip(),
            'user_agent'      => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
        );
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

        $stored['capture_extended'] = ! empty( $stored['capture_extended'] );
        $stored['extra_namespaces'] = $this->sanitize_namespaces_list( isset( $stored['extra_namespaces'] ) ? $stored['extra_namespaces'] : array() );

        $this->settings = wp_parse_args( $stored, $defaults );

        return $this->settings;
    }

    /**
     * Clear cached settings.
     */
    public function refresh_settings() {
        $this->settings = null;
    }

    /**
     * Default settings for the logger.
     *
     * @return array
     */
    public static function get_default_settings() {
        return array(
            'capture_extended' => false,
            'extra_namespaces' => array(),
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
     * Check if extended capture is enabled.
     *
     * @return bool
     */
    private function is_extended_capture_enabled() {
        $settings = $this->get_settings();

        return ! empty( $settings['capture_extended'] );
    }
}
