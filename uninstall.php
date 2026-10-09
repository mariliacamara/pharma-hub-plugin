<?php
/**
 * Removes the plugin's settings when it is deleted from WordPress.
 *
 * The token in wp-config.php, if any, is left for the site owner to remove.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'pharma_hub_url' );
delete_option( 'pharma_hub_token' );
delete_option( 'pharma_hub_ean_meta_key' );
delete_transient( 'pharma_hub_store' );
