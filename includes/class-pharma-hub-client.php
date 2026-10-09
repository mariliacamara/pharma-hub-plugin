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
     * The price report: the latest comparison of each offer, every page.
     *
     * The first page carries the collection, the thresholds and the summary;
     * the rows of the following pages are added to it.
     *
     * @param string $state active (listed and in stock) or all.
     * @return array run, easyAdjustCents, checkLinkPercent, summary and rows.
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public function get_report( $state = 'active' ) {
        $report = null;
        $after  = null;
        for ( $page = 0; $page < 100; $page++ ) {
            $query = array(
                'limit' => 200,
                'state' => $state,
            );
            if ( null !== $after ) {
                $query['after'] = $after;
            }
            $answer = $this->request( 'GET', '/v1/plugin/kuantokusta/report', $query );
            $rows   = isset( $answer['rows'] ) && is_array( $answer['rows'] ) ? $answer['rows'] : array();
            if ( null === $report ) {
                $report         = $answer;
                $report['rows'] = $rows;
            } else {
                $report['rows'] = array_merge( $report['rows'], $rows );
            }
            $after = isset( $answer['nextCursor'] ) && is_string( $answer['nextCursor'] ) ? $answer['nextCursor'] : null;
            if ( null === $after ) {
                unset( $report['nextCursor'] );
                return $report;
            }
        }
        throw new Pharma_Hub_Error( 'plugin_too_many_pages', 'The hub kept returning pages of the report' );
    }

    /**
     * The shape of an offer id of the hub: a positive whole number.
     */
    const OFFER_ID_PATTERN = '/^[1-9]\d{0,17}$/';

    /**
     * Pages of 200 comparisons read for one offer's history: 1,000 entries,
     * more than two years of daily collections.
     */
    const HISTORY_PAGES = 5;

    /**
     * Whether a value has the shape of an offer id of the hub.
     *
     * @param mixed $offer_id Value to check.
     * @return bool
     */
    public static function is_offer_id( $offer_id ) {
        return is_string( $offer_id ) && 1 === preg_match( self::OFFER_ID_PATTERN, $offer_id );
    }

    /**
     * The comparisons of one offer, newest first: one for each collection
     * that reached it.
     *
     * Follows the hub's pages up to HISTORY_PAGES. "complete" is false when
     * older comparisons were left unread.
     *
     * @param string $offer_id Hub offer id.
     * @return array{entries: array[], complete: bool}
     * @throws Pharma_Hub_Error When the id is not an offer id, or the hub refuses (404 for an offer of another store).
     */
    public function get_offer_history( $offer_id ) {
        if ( ! self::is_offer_id( $offer_id ) ) {
            throw new Pharma_Hub_Error( 'plugin_bad_offer_id', 'Not an offer id' );
        }
        $entries = array();
        $before  = null;
        for ( $page = 0; $page < self::HISTORY_PAGES; $page++ ) {
            $query = array( 'limit' => 200 );
            if ( null !== $before ) {
                $query['before'] = $before;
            }
            $answer = $this->request( 'GET', '/v1/plugin/kuantokusta/offers/' . $offer_id . '/history', $query );
            if ( isset( $answer['entries'] ) && is_array( $answer['entries'] ) ) {
                $entries = array_merge( $entries, $answer['entries'] );
            }
            $before = isset( $answer['nextCursor'] ) && is_string( $answer['nextCursor'] ) ? $answer['nextCursor'] : null;
            if ( null === $before ) {
                return array(
                    'entries'  => $entries,
                    'complete' => true,
                );
            }
        }
        return array(
            'entries'  => $entries,
            'complete' => false,
        );
    }

    /**
     * The shape of a collection id: a UUID.
     */
    const RUN_ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Asks the hub for a new collection of competitor prices.
     *
     * The hub answers at once; the collection itself takes minutes. If one
     * is already waiting or running, the hub returns it instead
     * (created = false), so asking twice never starts two.
     *
     * @param string $requested_by Who pressed the button, for the hub's history.
     * @return array{run: array, created: bool}
     * @throws Pharma_Hub_Error For example kk_run_too_soon (429, with Retry-After) or kk_key_missing (409).
     */
    public function start_run( $requested_by ) {
        // One line of at most 120 characters, as the hub accepts.
        $requested_by = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', (string) $requested_by ) );
        $requested_by = function_exists( 'mb_substr' ) ? mb_substr( $requested_by, 0, 120 ) : substr( $requested_by, 0, 120 );
        $body         = '' === $requested_by ? array() : array( 'requestedBy' => $requested_by );

        return $this->request( 'POST', '/v1/plugin/kuantokusta/runs', array(), (object) $body );
    }

    /**
     * One collection: how far it is and how it ended.
     *
     * @param string $run_id Collection id.
     * @return array{run: array, summary: array}
     * @throws Pharma_Hub_Error When the id is not a collection id, or the hub refuses.
     */
    public function get_run( $run_id ) {
        if ( ! is_string( $run_id ) || 1 !== preg_match( self::RUN_ID_PATTERN, $run_id ) ) {
            throw new Pharma_Hub_Error( 'plugin_bad_run_id', 'Not a collection id' );
        }
        return $this->request( 'GET', '/v1/plugin/kuantokusta/runs/' . $run_id );
    }

    /**
     * The store's most recent collection, finished or not.
     *
     * @return array{run: array|null, summary: array|null}
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public function get_latest_run() {
        return $this->request( 'GET', '/v1/plugin/kuantokusta/runs/latest' );
    }

    /**
     * The largest "easy adjust" threshold the hub accepts, in cents.
     */
    const MAX_EASY_ADJUST_CENTS = 100000;

    /**
     * The store's "easy adjust" threshold: an offer is an easy adjust when
     * the store is more expensive by at most this many cents.
     *
     * @return array{cents: int}
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public function get_easy_adjust() {
        return $this->request( 'GET', '/v1/plugin/kuantokusta/settings/easy-adjust' );
    }

    /**
     * Changes the store's "easy adjust" threshold. The hub applies it on the
     * next read of the report; nothing is recalculated.
     *
     * @param int $cents Whole number of cents, from 0 to MAX_EASY_ADJUST_CENTS.
     * @return array{cents: int}
     * @throws Pharma_Hub_Error When the value is out of range, or the hub refuses.
     */
    public function put_easy_adjust( $cents ) {
        if ( ! is_int( $cents ) || $cents < 0 || $cents > self::MAX_EASY_ADJUST_CENTS ) {
            throw new Pharma_Hub_Error( 'plugin_bad_cents', 'Not a whole number of cents in range' );
        }
        return $this->request( 'PUT', '/v1/plugin/kuantokusta/settings/easy-adjust', array(), array( 'cents' => $cents ) );
    }

    /**
     * Calls the hub.
     *
     * @param string            $method HTTP method.
     * @param string            $path   Path starting with /v1/.
     * @param array             $query  Query parameters.
     * @param array|object|null $body   JSON body, or null for none. An empty object is sent as {}.
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
