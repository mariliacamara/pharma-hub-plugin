<?php
/**
 * Plugin Name: ZincoGroup Hub
 * Description: Relatório de preços do KuantoKusta: compara os preços da loja com os das outras lojas, a partir do ZincoGroup Hub.
 * Version: 0.6.0
 * Requires at least: 6.1
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * Author: Camorim Tech
 * Text Domain: pharma-hub-plugin
 *
 * The plugin shows what the hub computed and links each KuantoKusta offer to
 * a WooCommerce product. It never calls KuantoKusta, never stores the
 * KuantoKusta key and never computes a comparison: the hub does.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'PHARMA_HUB_PLUGIN_VERSION', '0.6.0' );
define( 'PHARMA_HUB_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-pharma-hub-secret-box.php';
require_once __DIR__ . '/includes/class-pharma-hub-error.php';
require_once __DIR__ . '/includes/class-pharma-hub-client.php';
require_once __DIR__ . '/includes/class-pharma-hub-settings.php';
require_once __DIR__ . '/includes/class-pharma-hub-linker.php';
require_once __DIR__ . '/includes/class-pharma-hub-links.php';
require_once __DIR__ . '/includes/class-pharma-hub-format.php';
require_once __DIR__ . '/includes/class-pharma-hub-report.php';
require_once __DIR__ . '/includes/class-pharma-hub-admin.php';
require_once __DIR__ . '/includes/class-pharma-hub-admin-links.php';
require_once __DIR__ . '/includes/class-pharma-hub-admin-report.php';
require_once __DIR__ . '/includes/class-pharma-hub-admin-runs.php';

register_activation_hook( __FILE__, array( 'Pharma_Hub_Links', 'install' ) );

/*
 * The plugin reads and writes no orders, so it is compatible with
 * WooCommerce's order tables (HPOS) by construction.
 */
add_action(
    'before_woocommerce_init',
    function () {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', PHARMA_HUB_PLUGIN_FILE, true );
        }
    }
);

if ( is_admin() ) {
    Pharma_Hub_Admin::register();
}
