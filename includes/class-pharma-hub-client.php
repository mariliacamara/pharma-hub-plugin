<?php
/**
 * Client of the hub's plugin API.
 *
 * Every call is made by PHP, server to server, with the token in the
 * Authorization header. The token never reaches the browser, never goes in
 * a URL and is never written to a log or an error message.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Exception messages here are for logs and are never printed: screens show
// Pharma_Hub_Admin::error_message(), which escapes what it prints.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

/**
 * Calls the hub and turns its answers into arrays or Pharma_Hub_Error.
 */
class Pharma_Hub_Client {

    /**
     * Seconds to wait for the hub. The hub answers from its database; a
     * collection never runs inside a request.
     */
    const TIMEOUT = 15;

    /**
     * Base address of the hub, without a trailing slash.
     *
     * @var string
     */
    private $base_url;

    /**
     * The plugin token (phk_...).
     *
     * @var string
     */
    private $token;

    /**
     * Sends the request: wp_remote_request, or a fake in tests.
     *
     * @var callable
     */
    private $transport;

    /**
     * Whether addresses that WordPress considers unsafe (localhost, private
     * networks) are allowed. Only for a hub running on a developer machine.
     *
     * @var bool
     */
    private $allow_local;

    /**
     * Creates a client.
     *
     * @param string        $base_url    Base address of the hub, already validated.
     * @param string        $token       The plugin token.
     * @param callable|null $transport   Function with the signature of wp_remote_request.
     * @param bool          $allow_local Allow a hub on localhost or a private network.
     */
    public function __construct( $base_url, $token, $transport = null, $allow_local = false ) {
        $this->base_url    = rtrim( $base_url, '/' );
        $this->token       = $token;
        $this->transport   = $transport ? $transport : 'wp_remote_request';
        $this->allow_local = (bool) $allow_local;
    }

    /**
     * The store the token belongs to: its name and the name to show.
     *
     * @return array{name: string, brandName: string}
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public function get_store() {
        return $this->request( 'GET', '/v1/plugin/store' );
    }

    /**
     * Whether the store has a KuantoKusta key in the hub, and its last four characters.
     *
     * @return array{configured: bool, lastFour: string|null, updatedAt: string|null}
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public function get_credential() {
        return $this->request( 'GET', '/v1/plugin/kuantokusta/credential' );
    }

    /**
     * Hands the store's KuantoKusta key to the hub, which checks it with
     * KuantoKusta and stores it encrypted. The plugin keeps no copy.
     *
     * @param string $api_key The KuantoKusta Seller API key.
     * @return array{configured: bool, lastFour: string|null, updatedAt: string|null}
     * @throws Pharma_Hub_Error When the hub refuses the key or cannot be reached.
     */
    public function put_credential( $api_key ) {
        return $this->request( 'PUT', '/v1/plugin/kuantokusta/credential', array(), array( 'apiKey' => $api_key ) );
    }

    /**
     * Every offer the store has listed on KuantoKusta, as last copied by the hub.
     *
     * Follows the hub's pages of 200 until the last one.
     *
     * @return array[] Offers: id, offerRef, sku, ean, name, storeUrl, productUrl, priceCents, stock...
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public function get_all_offers() {
        $offers = array();
        $after  = null;
        // 100 pages of 200 is far more than any store's catalogue on
        // KuantoKusta; the bound only stops a hub that never ends.
        for ( $page = 0; $page < 100; $page++ ) {
            $query = array( 'limit' => 200 );
            if ( null !== $after ) {
                $query['after'] = $after;
            }
            $answer = $this->request( 'GET', '/v1/plugin/kuantokusta/offers', $query );
            if ( isset( $answer['offers'] ) && is_array( $answer['offers'] ) ) {
                $offers = array_merge( $offers, $answer['offers'] );
            }
            $after = isset( $answer['nextCursor'] ) && is_string( $answer['nextCursor'] ) ? $answer['nextCursor'] : null;
            if ( null === $after ) {
                return $offers;
            }
        }
        throw new Pharma_Hub_Error( 'plugin_too_many_pages', 'The hub kept returning pages of offers' );
    }

    /**
     * Calls the hub.
     *
     * @param string     $method HTTP method.
     * @param string     $path   Path starting with /v1/.
     * @param array      $query  Query parameters.
     * @param array|null $body   JSON body, or null for none.
     * @return array Decoded JSON answer.
     * @throws Pharma_Hub_Error When the call fails, with the hub's code when it gave one.
     */
    public function request( $method, $path, $query = array(), $body = null ) {
        $url = $this->base_url . $path;
        if ( $query ) {
            $url .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
        }

        $args = array(
            'method'             => $method,
            'timeout'            => self::TIMEOUT,
            // A redirect would send the token to another address.
            'redirection'        => 0,
            'reject_unsafe_urls' => ! $this->allow_local,
            'sslverify'          => true,
            'user-agent'         => 'pharma-hub-plugin/' . PHARMA_HUB_PLUGIN_VERSION,
            'headers'            => array(
                'Authorization' => 'Bearer ' . $this->token,
                'Accept'        => 'application/json',
            ),
        );
        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }

        $response = call_user_func( $this->transport, $url, $args );

        if ( is_wp_error( $response ) ) {
            // The WP_Error message may quote the URL; it never holds the token.
            throw new Pharma_Hub_Error( 'plugin_unreachable', $response->get_error_message() );
        }

        $status  = isset( $response['response']['code'] ) ? (int) $response['response']['code'] : 0;
        $decoded = json_decode( isset( $response['body'] ) ? (string) $response['body'] : '', true );

        if ( $status >= 200 && $status < 300 ) {
            if ( ! is_array( $decoded ) ) {
                throw new Pharma_Hub_Error( 'plugin_bad_response', 'The hub answered with something that is not JSON', $status );
            }
            return $decoded;
        }

        if ( $status >= 300 && $status < 400 ) {
            throw new Pharma_Hub_Error( 'plugin_redirected', 'The hub answered with a redirect, which is not followed', $status );
        }

        if ( is_array( $decoded ) && isset( $decoded['error']['code'] ) && is_string( $decoded['error']['code'] ) ) {
            throw new Pharma_Hub_Error(
                $decoded['error']['code'],
                isset( $decoded['error']['message'] ) ? (string) $decoded['error']['message'] : '',
                $status,
                self::retry_after( $response ),
                isset( $decoded['requestId'] ) && is_string( $decoded['requestId'] ) ? $decoded['requestId'] : null
            );
        }

        throw new Pharma_Hub_Error( 'plugin_http_error', 'The hub answered with HTTP ' . $status, $status, self::retry_after( $response ) );
    }

    /**
     * Seconds from the Retry-After header, when it is a whole number.
     *
     * @param array $response Response in the format of wp_remote_request.
     * @return int|null
     */
    private static function retry_after( $response ) {
        if ( ! isset( $response['headers'] ) ) {
            return null;
        }
        // WordPress gives a case-insensitive dictionary; tests give an array
        // with lower-case keys. Both answer to the lower-case name.
        $headers = $response['headers'];
        $value   = isset( $headers['retry-after'] ) ? $headers['retry-after'] : null;

        return is_string( $value ) && preg_match( '/^\d{1,6}$/', $value ) ? (int) $value : null;
    }
}
