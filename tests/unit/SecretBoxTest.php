<?php

use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase {

    public function test_opens_what_it_sealed() {
        $box    = new Pharma_Hub_Secret_Box( 'site-keys' );
        $sealed = $box->seal( 'phk_secret' );

        $this->assertSame( 'phk_secret', $box->open( $sealed ) );
        $this->assertStringStartsWith( 'v1:', $sealed );
        $this->assertStringNotContainsString( 'phk_secret', $sealed );
    }

    public function test_seals_the_same_secret_differently_each_time() {
        $box = new Pharma_Hub_Secret_Box( 'site-keys' );

        $this->assertNotSame( $box->seal( 'phk_secret' ), $box->seal( 'phk_secret' ) );
    }

    public function test_cannot_open_with_other_site_keys() {
        $sealed = ( new Pharma_Hub_Secret_Box( 'old-keys' ) )->seal( 'phk_secret' );

        $this->assertNull( ( new Pharma_Hub_Secret_Box( 'new-keys' ) )->open( $sealed ) );
    }

    public function test_refuses_a_changed_or_malformed_value() {
        $box    = new Pharma_Hub_Secret_Box( 'site-keys' );
        $sealed = $box->seal( 'phk_secret' );
        $raw    = base64_decode( substr( $sealed, 3 ) );
        $raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );

        $this->assertNull( $box->open( 'v1:' . base64_encode( $raw ) ) );
        $this->assertNull( $box->open( 'phk_secret' ) );
        $this->assertNull( $box->open( 'v1:not base64!' ) );
        $this->assertNull( $box->open( 'v1:' . base64_encode( 'short' ) ) );
        $this->assertNull( $box->open( null ) );
    }
}
