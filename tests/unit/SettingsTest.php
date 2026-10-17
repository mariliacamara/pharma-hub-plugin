<?php

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

    /**
     * @dataProvider urls
     */
    public function test_accepts_only_https_addresses( $url, $allow_local, $expected ) {
        $this->assertSame( $expected, Pharma_Hub_Settings::normalize_url( $url, $allow_local ) );
    }

    public function urls() {
        return array(
            'https'                    => array( 'https://hub.example.com', false, 'https://hub.example.com' ),
            'trailing slash removed'   => array( ' https://hub.example.com/ ', false, 'https://hub.example.com' ),
            'with a path'              => array( 'https://example.com/hub/', false, 'https://example.com/hub' ),
            'upper-case scheme'        => array( 'HTTPS://hub.example.com', false, 'HTTPS://hub.example.com' ),
            'http refused'             => array( 'http://hub.example.com', false, null ),
            'http on a local site'     => array( 'http://localhost:7000', true, 'http://localhost:7000' ),
            'credentials in the url'   => array( 'https://user:pass@hub.example.com', false, null ),
            'query string'             => array( 'https://hub.example.com?token=x', false, null ),
            'fragment'                 => array( 'https://hub.example.com#x', false, null ),
            'no scheme'                => array( 'hub.example.com', false, null ),
            'other scheme'             => array( 'ftp://hub.example.com', true, null ),
            'javascript'               => array( 'javascript:alert(1)', true, null ),
            'empty'                    => array( '', false, null ),
        );
    }

    public function test_recognises_a_token_by_its_shape() {
        $this->assertTrue( Pharma_Hub_Settings::is_valid_token( 'phk_' . str_repeat( 'aZ0_-', 8 ) . 'abc' ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_token( 'phk_' . str_repeat( 'a', 42 ) ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_token( 'phk_' . str_repeat( 'a', 44 ) ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_token( 'Bearer phk_' . str_repeat( 'a', 43 ) ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_token( 'phk_' . str_repeat( 'a', 42 ) . '=' ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_token( null ) );
    }

    public function test_accepts_a_meta_key_or_nothing_for_the_ean() {
        $this->assertTrue( Pharma_Hub_Settings::is_valid_meta_key( '' ) );
        $this->assertTrue( Pharma_Hub_Settings::is_valid_meta_key( '_wpm_gtin_code' ) );
        $this->assertTrue( Pharma_Hub_Settings::is_valid_meta_key( 'EAN' ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_meta_key( "_ean'; DROP TABLE" ) );
        $this->assertFalse( Pharma_Hub_Settings::is_valid_meta_key( str_repeat( 'a', 192 ) ) );
    }

    public function test_guesses_the_brand_attribute_until_one_is_chosen() {
        $this->assertSame( 'pa_marca', Pharma_Hub_Settings::pick_brand_attribute( null, array( 'pa_cor', 'pa_brand', 'pa_marca' ) ) );
        $this->assertSame( 'pa_brand', Pharma_Hub_Settings::pick_brand_attribute( false, array( 'pa_cor', 'pa_brand' ) ) );
        $this->assertSame( '', Pharma_Hub_Settings::pick_brand_attribute( null, array( 'pa_cor' ) ) );
        $this->assertSame( '', Pharma_Hub_Settings::pick_brand_attribute( null, array() ) );
    }

    public function test_keeps_the_chosen_brand_attribute_while_it_exists() {
        $this->assertSame( 'pa_fabricante', Pharma_Hub_Settings::pick_brand_attribute( 'pa_fabricante', array( 'pa_marca', 'pa_fabricante' ) ) );
        // "None" was chosen: no guess, even with pa_marca in the store.
        $this->assertSame( '', Pharma_Hub_Settings::pick_brand_attribute( '', array( 'pa_marca' ) ) );
        // The chosen attribute was deleted from the store.
        $this->assertSame( '', Pharma_Hub_Settings::pick_brand_attribute( 'pa_fabricante', array( 'pa_marca' ) ) );
    }
}
