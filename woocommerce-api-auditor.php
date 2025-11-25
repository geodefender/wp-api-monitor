<?php
/**
 * Plugin Name: WooCommerce API Auditor
 * Description: Registra y muestra todas las solicitudes realizadas a la API REST de WooCommerce.
 * Version: 1.0.0
 * Author: inmensus.ai
 * Text Domain: wc-api-auditor
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'WC_API_AUDITOR_VERSION', '1.0.0' );
define( 'WC_API_AUDITOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_API_AUDITOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_API_AUDITOR_TABLE', 'wp_wc_api_audit_log' );

require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-installer.php';
require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-logger.php';
require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-admin.php';

register_activation_hook( __FILE__, array( 'WC_API_Auditor_Installer', 'install' ) );

/**
 * Initialise plugin components.
 */
function wc_api_auditor_init() {
    WC_API_Auditor_Logger::get_instance()->init();

    if ( is_admin() ) {
        WC_API_Auditor_Admin::get_instance()->init();
    }
}
add_action( 'plugins_loaded', 'wc_api_auditor_init' );
