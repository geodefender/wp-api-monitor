<?php
/**
 * Installer for WooCommerce API Auditor.
 *
 * @package WC_API_Auditor
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles table creation on activation.
 */
class WC_API_Auditor_Installer {

    /**
     * Create the audit log table.
     */
    public static function install() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $table_name      = $wpdb->prefix . 'wc_api_audit_log';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            timestamp datetime NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent text NULL,
            http_method varchar(10) NOT NULL,
            endpoint text NOT NULL,
            api_key_id bigint(20) NULL,
            api_key_display varchar(191) NULL,
            request_payload longtext NULL,
            raw_body longtext NULL,
            response_code int(11) NULL,
            response_body longtext NULL,
            PRIMARY KEY  (id),
            KEY api_key_id (api_key_id),
            KEY http_method (http_method),
            KEY endpoint (endpoint(191)),
            KEY api_key_display (api_key_display),
            KEY idx_timestamp_method_display (timestamp DESC, http_method, api_key_display),
            KEY timestamp (timestamp)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'wc_api_auditor_db_version', WC_API_AUDITOR_DB_VERSION );

        if ( ! get_option( 'wc_api_auditor_settings' ) ) {
            update_option( 'wc_api_auditor_settings', WC_API_Auditor_Logger::get_default_settings() );
        }

        if ( ! wp_next_scheduled( 'wc_api_auditor_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'wc_api_auditor_cleanup' );
        }
    }

    /**
     * Update the audit log table when schema changes.
     */
    public static function maybe_update() {
        $installed_version = get_option( 'wc_api_auditor_db_version' );

        if ( version_compare( $installed_version, WC_API_AUDITOR_DB_VERSION, '<' ) ) {
            self::install();
        }

        if ( ! wp_next_scheduled( 'wc_api_auditor_cleanup' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'wc_api_auditor_cleanup' );
        }
    }
}
