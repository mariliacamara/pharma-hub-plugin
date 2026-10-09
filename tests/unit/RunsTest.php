<?php

use PHPUnit\Framework\TestCase;

final class RunsTest extends TestCase {

    const TOKEN  = 'phk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    const RUN_ID = '0199c5a8-7b1e-7c3d-9e2f-0a1b2c3d4e5f';

    /**
     * Requests sent, as array( url, args ).
     *
     * @var array
     */
    private $sent = array();

    private function client( $status = 202, $body = array( 'run' => array( 'id' => self::RUN_ID, 'status' => 'queued' ), 'created' => true ) ) {
        $sent = &$this->sent;
        return new Pharma_Hub_Client(
            'https://hub.example.com',
            self::TOKEN,
            function ( $url, $args ) use ( &$sent, $status, $body ) {
                $sent[] = array( $url, $args );
                return array(
                    'response' => array( 'code' => $status ),
                    'body'     => json_encode( $body ),
                    'headers'  => array( 'retry-after' => '840' ),
                );
            }
        );
    }

    public function test_asks_for_a_collection_saying_who_asked() {
        $answer = $this->client()->start_run( 'maria.silva' );

        list( $url, $args ) = $this->sent[0];
        $this->assertSame( 'https://hub.example.com/v1/plugin/kuantokusta/runs', $url );
        $this->assertSame( 'POST', $args['method'] );
        $this->assertSame( '{"requestedBy":"maria.silva"}', $args['body'] );
        $this->assertSame( self::RUN_ID, $answer['run']['id'] );
    }

    public function test_sends_one_line_of_at_most_120_characters_or_nothing() {
        $client = $this->client();
        $client->start_run( "maria\nsilva\t" . str_repeat( 'x', 200 ) );
        $client->start_run( "  \n " );

        $first = json_decode( $this->sent[0][1]['body'], true );
        $this->assertSame( 120, strlen( $first['requestedBy'] ) );
        $this->assertStringStartsWith( 'maria silva x', $first['requestedBy'] );
        $this->assertSame( '{}', $this->sent[1][1]['body'] );
    }

    public function test_reports_a_collection_asked_too_soon_with_the_time_to_wait() {
        try {
            $this->client( 429, array( 'error' => array( 'code' => 'kk_run_too_soon', 'message' => 'x' ) ) )->start_run( 'maria' );
            $this->fail( 'No error' );
        } catch ( Pharma_Hub_Error $error ) {
            $this->assertSame( 840, $error->get_retry_after() );
            $this->assertMatchesRegularExpression(
                '/^Uma coleta terminou há pouco e o relatório já está atualizado\. Pode pedir outra a partir das \d\d:\d\d\.$/u',
                Pharma_Hub_Admin::error_message( $error )
            );
        }
    }

    public function test_explains_a_missing_kuantokusta_key() {
        $message = Pharma_Hub_Admin::error_message( new Pharma_Hub_Error( 'kk_key_missing', 'x', 409 ) );

        $this->assertStringContainsString( 'chave da API do KuantoKusta', $message );
    }

    public function test_follows_one_collection_by_its_id() {
        $client = $this->client( 200, array( 'run' => array( 'status' => 'running' ) ) );
        $client->get_run( self::RUN_ID );
        $client->get_latest_run();

        $this->assertSame( 'https://hub.example.com/v1/plugin/kuantokusta/runs/' . self::RUN_ID, $this->sent[0][0] );
        $this->assertSame( 'https://hub.example.com/v1/plugin/kuantokusta/runs/latest', $this->sent[1][0] );
    }

    public function test_refuses_an_id_that_is_not_a_collection_without_calling_the_hub() {
        foreach ( array( '../credential', 'latest', '', self::RUN_ID . '/x', null ) as $id ) {
            try {
                $this->client()->get_run( $id );
                $this->fail( 'No error for ' . var_export( $id, true ) );
            } catch ( Pharma_Hub_Error $error ) {
                $this->assertSame( 'plugin_bad_run_id', $error->get_error_code() );
            }
        }
        $this->assertSame( array(), $this->sent );
    }

    /**
     * @dataProvider runs
     */
    public function test_says_how_a_collection_is_going( $run, $expected ) {
        $this->assertSame( $expected, Pharma_Hub_Admin_Runs::status_message( $run ) );
    }

    public function runs() {
        return array(
            'queued'          => array( array( 'status' => 'queued' ), 'Coleta pedida; está na fila.' ),
            'copying offers'  => array( array( 'status' => 'running', 'progress' => array( 'total' => 0, 'read' => 0, 'notRead' => 0 ) ), 'A preparar a coleta…' ),
            'reading pages'   => array( array( 'status' => 'running', 'progress' => array( 'total' => 185, 'read' => 38, 'notRead' => 2 ) ), 'A recolher preços: 40 de 185 páginas.' ),
            'ended'           => array( array( 'status' => 'blocked' ), 'Coleta terminada. A atualizar o relatório…' ),
            'no answer'       => array( array(), 'Coleta terminada. A atualizar o relatório…' ),
        );
    }
}
