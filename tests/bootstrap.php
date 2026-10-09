<?php
/**
 * Loads the plugin's classes without WordPress.
 *
 * Only the few WordPress functions the tested code calls are stubbed, with
 * the same behaviour as WordPress for the inputs the tests use. Anything
 * that needs a real WordPress belongs in a test against a real site.
 *
 * @package Pharma_Hub_Plugin
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'PHARMA_HUB_PLUGIN_VERSION', 'test' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Minimal WP_Error.
 */
class WP_Error {
    /**
     * Message.
     *
     * @var string
     */
    private $message;

    /**
     * Creates the error.
     *
     * @param string $code    Code.
     * @param string $message Message.
     */
    public function __construct( $code = '', $message = '' ) {
        $this->message = $message;
    }

    /**
     * Message.
     *
     * @return string
     */
    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

function wp_json_encode( $data ) {
    return json_encode( $data );
}

function wp_parse_url( $url ) {
    return parse_url( $url );
}

function __( $text ) {
    return $text;
}

require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-secret-box.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-error.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-client.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-linker.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-format.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-report.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-pharma-hub-admin-runs.php';
