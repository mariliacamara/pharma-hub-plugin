<?php

use PHPUnit\Framework\TestCase;

final class LinkerTest extends TestCase {

    /**
     * Lookups asked, as "key:value".
     *
     * @var string[]
     */
    private $asked = array();

    /**
     * A linker over a fake catalogue.
     *
     * @param array $sku Map of SKU to product ids.
     * @param array $ean Map of EAN to product ids.
     * @param array $url Map of address to product ids.
     * @return Pharma_Hub_Linker
     */
    private function linker( $sku = array(), $ean = array(), $url = array() ) {
        $asked  = &$this->asked;
        $lookup = function ( $key, $map ) use ( &$asked ) {
            return function ( $value ) use ( $key, $map, &$asked ) {
                $asked[] = $key . ':' . $value;
                return isset( $map[ $value ] ) ? $map[ $value ] : array();
            };
        };
        return new Pharma_Hub_Linker( $lookup( 'sku', $sku ), $lookup( 'ean', $ean ), $lookup( 'url', $url ) );
    }

    private static function offer( $sku, $ean = null, $url = null ) {
        return array(
            'sku'      => $sku,
            'ean'      => $ean,
            'storeUrl' => $url,
        );
    }

    public function test_links_by_sku_first() {
        $found = $this->linker( array( '7076406' => array( 10 ) ), array( '8470003846776' => array( 20 ) ) )
            ->find( self::offer( '7076406', '8470003846776' ) );

        $this->assertSame( array( 'result' => 'linked', 'product_id' => 10, 'method' => 'sku' ), $found );
        $this->assertSame( array( 'sku:7076406' ), $this->asked );
    }

    public function test_falls_back_to_the_ean_then_the_store_address() {
        $linker = $this->linker(
            array(),
            array( '4052199675978' => array( 20 ) ),
            array( 'https://zincomed.com/produto/x/' => array( 30 ) )
        );

        $this->assertSame( 'ean', $linker->find( self::offer( 'MANUFACTURER-1', '4052199675978' ) )['method'] );
        $by_url = $linker->find( self::offer( null, null, 'https://zincomed.com/produto/x/' ) );
        $this->assertSame( array( 'result' => 'linked', 'product_id' => 30, 'method' => 'url' ), $by_url );
    }

    public function test_does_not_guess_when_a_key_finds_several_products() {
        $found = $this->linker( array( '6321125' => array( 10, 11 ) ) )->find( self::offer( '6321125' ) );

        $this->assertSame( array( 'result' => 'ambiguous', 'product_id' => null, 'method' => 'sku' ), $found );
    }

    public function test_a_precise_key_wins_over_an_ambiguous_one() {
        $found = $this->linker( array( '6321125' => array( 10, 11 ) ), array( '4052199663463' => array( 11 ) ) )
            ->find( self::offer( '6321125', '4052199663463' ) );

        $this->assertSame( array( 'result' => 'linked', 'product_id' => 11, 'method' => 'ean' ), $found );
    }

    public function test_counts_the_same_product_twice_as_one() {
        $found = $this->linker( array( '6321109' => array( 10, '10' ) ) )->find( self::offer( '6321109' ) );

        $this->assertSame( 'linked', $found['result'] );
    }

    public function test_reports_an_offer_that_matches_nothing() {
        $found = $this->linker()->find( self::offer( '1', '4052199663364', 'https://zincomed.com/p/' ) );

        $this->assertSame( array( 'result' => 'none', 'product_id' => null, 'method' => null ), $found );
        $this->assertSame( array( 'sku:1', 'ean:4052199663364', 'url:https://zincomed.com/p/' ), $this->asked );
    }

    public function test_skips_keys_the_offer_does_not_have() {
        $this->linker()->find( array() );
        $this->linker()->find( self::offer( '  ', 'not an ean', 'javascript:alert(1)' ) );

        $this->assertSame( array(), $this->asked );
    }

    /**
     * @dataProvider eans
     */
    public function test_cleans_the_ean( $raw, $expected ) {
        $this->assertSame( $expected, Pharma_Hub_Linker::clean_ean( $raw ) );
    }

    public function eans() {
        return array(
            'EAN-13'              => array( '4052199663364', '4052199663364' ),
            'spaces and hyphens'  => array( ' 405-2199 663364 ', '4052199663364' ),
            'EAN-8'               => array( '96385074', '96385074' ),
            'GTIN-14'             => array( '04052199663364', '04052199663364' ),
            'too short'           => array( '1234567', null ),
            'too long'            => array( '123456789012345', null ),
            'letters'             => array( '40521996633X4', null ),
            'number'              => array( 96385074, '96385074' ),
            'null'                => array( null, null ),
        );
    }

    public function test_trims_the_sku() {
        $this->assertSame( '7135541', Pharma_Hub_Linker::clean_sku( ' 7135541 ' ) );
        $this->assertNull( Pharma_Hub_Linker::clean_sku( '' ) );
        $this->assertNull( Pharma_Hub_Linker::clean_sku( 7135541 ) );
    }
}
