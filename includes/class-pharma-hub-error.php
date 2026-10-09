<?php
/**
 * A failed call to the hub.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Carries the hub's error code, so screens branch on the code and never on
 * the message. Codes the plugin produces itself start with "plugin_".
 */
class Pharma_Hub_Error extends Exception {

    /**
     * Error code, such as kk_run_too_soon or plugin_unreachable.
     *
     * @var string
     */
    private $error_code;

    /**
     * HTTP status of the answer; 0 when there was none.
     *
     * @var int
     */
    private $status;

    /**
     * Seconds to wait before asking again, from the Retry-After header.
     *
     * @var int|null
     */
    private $retry_after;

    /**
     * The hub's request id, to find the request in the hub's log.
     *
     * @var string|null
     */
    private $request_id;

    /**
     * Creates the error.
     *
     * @param string      $error_code  Error code.
     * @param string      $message     Technical message, for logs. Never shown as is.
     * @param int         $status      HTTP status, 0 when there was no answer.
     * @param int|null    $retry_after Seconds from Retry-After.
     * @param string|null $request_id  The hub's request id.
     */
    public function __construct( $error_code, $message, $status = 0, $retry_after = null, $request_id = null ) {
        parent::__construct( $message );
        $this->error_code  = $error_code;
        $this->status      = (int) $status;
        $this->retry_after = $retry_after;
        $this->request_id  = $request_id;
    }

    /**
     * Error code.
     *
     * @return string
     */
    public function get_error_code() {
        return $this->error_code;
    }

    /**
     * HTTP status, 0 when there was no answer.
     *
     * @return int
     */
    public function get_status() {
        return $this->status;
    }

    /**
     * Seconds to wait before asking again, or null.
     *
     * @return int|null
     */
    public function get_retry_after() {
        return $this->retry_after;
    }

    /**
     * The hub's request id, or null.
     *
     * @return string|null
     */
    public function get_request_id() {
        return $this->request_id;
    }
}
