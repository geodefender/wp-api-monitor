<?php
/*
Plugin Name: WP API Monitor
Plugin URI: https://wordpress.org/plugins/wp-api-monitor/
Description: Monitor, search and debug all REST API requests and responses in WordPress in real time.
Version: 1.0.1
Author: Enrique Orione
Author URI: https://github.com/geodefender
License: GPLv2 or later
Text Domain: wp-api-monitor
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants.
define( 'WC_API_AUDITOR_VERSION', '1.0.0' );
define( 'WC_API_AUDITOR_DB_VERSION', '1.3.0' );
define( 'WC_API_AUDITOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_API_AUDITOR_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_API_AUDITOR_TABLE', 'wp_wc_api_audit_log' );
define( 'WC_API_AUDITOR_GITHUB_TOKEN', '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' );

require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-installer.php';
require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-logger.php';
require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-admin.php';
require_once WC_API_AUDITOR_PATH . 'includes/class-wc-api-auditor-updater.php';

register_activation_hook( __FILE__, array( 'WC_API_Auditor_Installer', 'install' ) );

/**
 * Initialise plugin components.
 */
function wc_api_auditor_init() {
    WC_API_Auditor_Installer::maybe_update();

    WC_API_Auditor_Logger::get_instance()->init();

    if ( is_admin() ) {
        WC_API_Auditor_Admin::get_instance()->init();
        WC_API_Auditor_Updater::get_instance()->init();
    }
}
add_action( 'plugins_loaded', 'wc_api_auditor_init' );
