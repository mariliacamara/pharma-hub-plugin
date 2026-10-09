<?php

use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {

    const TOKEN = 'phk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /**
     * Requests the fake transport received, as array( url, args ).
     *
     * @var array
     */
    private $sent = array();

    private function client_answering( $response, $allow_local = false ) {
        $sent = &$this->sent;
        return new Pharma_Hub_Client(
            'https://hub.example.com/',
            self::TOKEN,
            function ( $url, $args ) use ( &$sent, $response ) {
                $sent[] = array( $url, $args );
                return $response;
            },
            $allow_local
        );
    }

    private static function answer( $status, $body, $headers = array() ) {
        return array(
            'response' => array( 'code' => $status ),
            'body'     => is_string( $body ) ? $body : json_encode( $body ),
            'headers'  => $headers,
        );
    }

    private function call_failing( Pharma_Hub_Client $client ) {
        try {
            $client->get_store();
        } catch ( Pharma_Hub_Error $error ) {
            return $error;
        }
        $this->fail( 'The call did not fail' );
    }

    public function test_sends_the_token_in_the_header_and_never_follows_redirects() {
        $client = $this->client_answering( self::answer( 200, array( 'name' => 'Zincomed', 'brandName' => 'ZincoGroup Hub' ) ) );

        $this->assertSame( array( 'name' => 'Zincomed', 'brandName' => 'ZincoGroup Hub' ), $client->get_store() );

        list( $url, $args ) = $this->sent[0];
        $this->assertSame( 'https://hub.example.com/v1/plugin/store', $url );
        $this->assertSame( 'GET', $args['method'] );
        $this->assertSame( 'Bearer ' . self::TOKEN, $args['headers']['Authorization'] );
        $this->assertSame( 0, $args['redirection'] );
        $this->assertTrue( $args['sslverify'] );
        $this->assertTrue( $args['reject_unsafe_urls'] );
        $this->assertArrayNotHasKey( 'body', $args );
        $this->assertStringNotContainsString( self::TOKEN, $url );
    }

    public function test_allows_a_local_hub_only_when_asked() {
        $client = $this->client_answering( self::answer( 200, array() ), true );
        $client->get_store();

        $this->assertFalse( $this->sent[0][1]['reject_unsafe_urls'] );
    }

    public function test_sends_the_kuantokusta_key_as_json() {
        $client = $this->client_answering( self::answer( 200, array( 'configured' => true, 'lastFour' => 'a1b2' ) ) );

        $this->assertSame( 'a1b2', $client->put_credential( 'kk-key' )['lastFour'] );

        list( $url, $args ) = $this->sent[0];
        $this->assertSame( 'https://hub.example.com/v1/plugin/kuantokusta/credential', $url );
        $this->assertSame( 'PUT', $args['method'] );
        $this->assertSame( 'application/json', $args['headers']['Content-Type'] );
        $this->assertSame( '{"apiKey":"kk-key"}', $args['body'] );
    }

    public function test_builds_the_query_string() {
        $client = $this->client_answering( self::answer( 200, array() ) );
        $client->request( 'GET', '/v1/plugin/kuantokusta/report', array( 'limit' => 200, 'after' => '12', 'outcome' => 'more expensive' ) );

        $this->assertSame( 'https://hub.example.com/v1/plugin/kuantokusta/report?limit=200&after=12&outcome=more%20expensive', $this->sent[0][0] );
    }

    public function test_reads_every_page_of_the_report() {
        $pages = array(
            self::answer( 200, array( 'run' => array( 'id' => 'r1' ), 'summary' => array( 'offers' => 3 ), 'rows' => array( array( 'offerId' => '1' ), array( 'offerId' => '2' ) ), 'nextCursor' => '2' ) ),
            self::answer( 200, array( 'run' => array( 'id' => 'r1' ), 'summary' => array( 'offers' => 3 ), 'rows' => array( array( 'offerId' => '5' ) ), 'nextCursor' => null ) ),
        );
        $urls   = array();
        $client = new Pharma_Hub_Client(
            'https://hub.example.com',
            self::TOKEN,
            function ( $url ) use ( &$pages, &$urls ) {
                $urls[] = $url;
                return array_shift( $pages );
            }
        );

        $report = $client->get_report( 'all' );

        $this->assertSame( array( '1', '2', '5' ), array_column( $report['rows'], 'offerId' ) );
        $this->assertSame( array( 'offers' => 3 ), $report['summary'] );
        $this->assertArrayNotHasKey( 'nextCursor', $report );
        $this->assertSame(
            array(
                'https://hub.example.com/v1/plugin/kuantokusta/report?limit=200&state=all',
                'https://hub.example.com/v1/plugin/kuantokusta/report?limit=200&state=all&after=2',
            ),
            $urls
        );
    }

    public function test_reports_the_hubs_error_code_retry_after_and_request_id() {
        $client = $this->client_answering(
            self::answer(
                429,
                array(
                    'error'     => array( 'code' => 'kk_run_too_soon', 'message' => 'A collection finished a moment ago' ),
                    'requestId' => 'req-1',
                ),
                array( 'retry-after' => '840' )
            )
        );

        $error = $this->call_failing( $client );

        $this->assertSame( 'kk_run_too_soon', $error->get_error_code() );
        $this->assertSame( 429, $error->get_status() );
        $this->assertSame( 840, $error->get_retry_after() );
        $this->assertSame( 'req-1', $error->get_request_id() );
    }

    public function test_ignores_a_retry_after_that_is_not_a_number_of_seconds() {
        $client = $this->client_answering(
            self::answer( 429, array( 'error' => array( 'code' => 'kk_run_too_soon' ) ), array( 'retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT' ) )
        );

        $this->assertNull( $this->call_failing( $client )->get_retry_after() );
    }

    public function test_refuses_a_redirect() {
        $error = $this->call_failing( $this->client_answering( self::answer( 302, '' ) ) );

        $this->assertSame( 'plugin_redirected', $error->get_error_code() );
    }

    public function test_reports_a_hub_that_cannot_be_reached() {
        $error = $this->call_failing( $this->client_answering( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ) );

        $this->assertSame( 'plugin_unreachable', $error->get_error_code() );
        $this->assertSame( 0, $error->get_status() );
    }

    public function test_reports_an_answer_that_is_not_json() {
        $this->assertSame( 'plugin_bad_response', $this->call_failing( $this->client_answering( self::answer( 200, '<html>' ) ) )->get_error_code() );
        $this->assertSame( 'plugin_http_error', $this->call_failing( $this->client_answering( self::answer( 502, '<html>Bad gateway</html>' ) ) )->get_error_code() );
    }

    public function test_never_puts_the_token_in_an_error() {
        $error = $this->call_failing(
            $this->client_answering( self::answer( 401, array( 'error' => array( 'code' => 'invalid_token', 'message' => 'A valid token is required' ) ) ) )
        );

        $this->assertStringNotContainsString( self::TOKEN, $error->getMessage() );
        $this->assertStringNotContainsString( self::TOKEN, Pharma_Hub_Admin::error_message( $error ) );
    }

    public function test_explains_errors_in_the_users_terms_with_the_reference() {
        $known   = new Pharma_Hub_Error( 'kk_key_rejected', 'technical', 422, null, 'req-9' );
        $unknown = new Pharma_Hub_Error( 'something_new', 'technical', 500 );

        $this->assertSame( 'O KuantoKusta não aceitou esta chave. Nada foi alterado. Referência: req-9', Pharma_Hub_Admin::error_message( $known ) );
        $this->assertSame( 'O hub respondeu de forma inesperada (something_new).', Pharma_Hub_Admin::error_message( $unknown ) );
    }

    public function test_reads_and_changes_the_easy_adjust_threshold() {
        $client = $this->client_answering( self::answer( 200, array( 'cents' => 25 ) ) );

        $this->assertSame( array( 'cents' => 25 ), $client->get_easy_adjust() );
        $this->assertSame( array( 'cents' => 25 ), $client->put_easy_adjust( 25 ) );

        list( $get_url, $get_args ) = $this->sent[0];
        $this->assertSame( 'https://hub.example.com/v1/plugin/kuantokusta/settings/easy-adjust', $get_url );
        $this->assertSame( 'GET', $get_args['method'] );

        list( $put_url, $put_args ) = $this->sent[1];
        $this->assertSame( $get_url, $put_url );
        $this->assertSame( 'PUT', $put_args['method'] );
        $this->assertSame( '{"cents":25}', $put_args['body'] );
        $this->assertSame( 'application/json', $put_args['headers']['Content-Type'] );
    }

    public function test_does_not_send_a_threshold_that_is_not_whole_cents_in_range() {
        $client = $this->client_answering( self::answer( 200, array( 'cents' => 10 ) ) );

        foreach ( array( -1, 100001, '10', 1.5, null ) as $value ) {
            try {
                $client->put_easy_adjust( $value );
                $this->fail( 'Accepted ' . var_export( $value, true ) );
            } catch ( Pharma_Hub_Error $error ) {
                $this->assertSame( 'plugin_bad_cents', $error->get_error_code() );
            }
        }
        $this->assertSame( array(), $this->sent );
    }

    public function test_reads_the_history_of_an_offer_page_by_page() {
        $pages = array(
            self::answer( 200, array( 'entries' => array( array( 'id' => '9' ), array( 'id' => '8' ) ), 'nextCursor' => '8' ) ),
            self::answer( 200, array( 'entries' => array( array( 'id' => '7' ) ), 'nextCursor' => null ) ),
        );
        $urls   = array();
        $client = new Pharma_Hub_Client(
            'https://hub.example.com',
            self::TOKEN,
            function ( $url ) use ( &$pages, &$urls ) {
                $urls[] = $url;
                return array_shift( $pages );
            }
        );

        $history = $client->get_offer_history( '42' );

        $this->assertSame( array( '9', '8', '7' ), array_column( $history['entries'], 'id' ) );
        $this->assertTrue( $history['complete'] );
        $this->assertSame(
            array(
                'https://hub.example.com/v1/plugin/kuantokusta/offers/42/history?limit=200',
                'https://hub.example.com/v1/plugin/kuantokusta/offers/42/history?limit=200&before=8',
            ),
            $urls
        );
    }

    public function test_stops_reading_a_history_that_never_ends_and_says_so() {
        $calls  = 0;
        $client = new Pharma_Hub_Client(
            'https://hub.example.com',
            self::TOKEN,
            function () use ( &$calls ) {
                ++$calls;
                return self::answer( 200, array( 'entries' => array( array( 'id' => (string) $calls ) ), 'nextCursor' => (string) $calls ) );
            }
        );

        $history = $client->get_offer_history( '42' );

        $this->assertSame( Pharma_Hub_Client::HISTORY_PAGES, $calls );
        $this->assertFalse( $history['complete'] );
        $this->assertCount( Pharma_Hub_Client::HISTORY_PAGES, $history['entries'] );
    }

    /**
     * @dataProvider not_offer_ids
     */
    public function test_never_asks_for_the_history_of_something_that_is_not_an_offer_id( $offer_id ) {
        $client = $this->client_answering( self::answer( 200, array( 'entries' => array() ) ) );
        try {
            $client->get_offer_history( $offer_id );
            $this->fail( 'The call did not fail' );
        } catch ( Pharma_Hub_Error $error ) {
            $this->assertSame( 'plugin_bad_offer_id', $error->get_error_code() );
        }
        $this->assertSame( array(), $this->sent );
    }

    public function not_offer_ids() {
        return array(
            'a path'        => array( '1/../../admin' ),
            'a query'       => array( '1?x=1' ),
            'zero'          => array( '0' ),
            'leading zero'  => array( '012' ),
            'a number'      => array( 12 ),
            'empty'         => array( '' ),
            'too long'      => array( '1234567890123456789' ),
        );
    }
}
