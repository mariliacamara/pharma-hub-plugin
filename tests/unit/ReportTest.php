<?php

use PHPUnit\Framework\TestCase;

final class ReportTest extends TestCase {

    private static function row( $id, $name, $outcome, $store, $difference = null, $extra = array() ) {
        return array_merge(
            array(
                'offerId'           => (string) $id,
                'name'              => $name,
                'outcome'           => $outcome,
                'storePriceCents'   => $store,
                'differenceCents'   => $difference,
                'differencePercent' => null === $difference ? null : $difference / max( $store, 1 ) * 100,
                'easyAdjust'        => false,
                'checkLink'         => false,
            ),
            $extra
        );
    }

    private static function rows() {
        return array(
            self::row( 1, 'Supradyn', 'more_expensive', 1599, 5, array( 'easyAdjust' => true ) ),
            self::row( 2, 'Molicare', 'cheapest', 175, -2614, array( 'checkLink' => true ) ),
            self::row( 3, 'antes', 'no_data', 1000 ),
            self::row( 4, 'Zinco', 'more_expensive', 1000, 300 ),
        );
    }

    private static function ids( $rows ) {
        return array_map(
            function ( $row ) {
                return $row['offerId'];
            },
            $rows
        );
    }

    public function test_keeps_only_known_filter_values() {
        $this->assertSame(
            array(
                'state'    => 'active',
                'outcome'  => '',
                'easy'     => false,
                'check'    => false,
                'unlinked' => false,
                'shipping' => false,
                'orderby'  => 'name',
                'order'    => 'asc',
            ),
            Pharma_Hub_Report::filters_from( array( 'state' => 'gone', 'outcome' => '<script>', 'easy' => 'yes', 'orderby' => array( 'x' ) ) )
        );

        $filters = Pharma_Hub_Report::filters_from( array( 'state' => 'all', 'outcome' => 'tied', 'easy' => '1', 'shipping' => '1', 'orderby' => 'percent', 'order' => 'desc' ) );
        $this->assertSame( array( 'all', 'tied', true, true, 'percent', 'desc' ), array( $filters['state'], $filters['outcome'], $filters['easy'], $filters['shipping'], $filters['orderby'], $filters['order'] ) );
    }

    public function test_filters_by_outcome_and_flags() {
        $filter = function ( $query, $links = array() ) {
            return self::ids( Pharma_Hub_Report::filter( self::rows(), Pharma_Hub_Report::filters_from( $query ), $links ) );
        };

        $this->assertSame( array( '1', '2', '3', '4' ), $filter( array() ) );
        $this->assertSame( array( '1', '4' ), $filter( array( 'outcome' => 'more_expensive' ) ) );
        $this->assertSame( array( '1' ), $filter( array( 'easy' => '1' ) ) );
        $this->assertSame( array( '2' ), $filter( array( 'check' => '1' ) ) );
        $this->assertSame( array(), $filter( array( 'easy' => '1', 'check' => '1' ) ) );
        $this->assertSame(
            array( '2', '3' ),
            $filter(
                array( 'unlinked' => '1' ),
                array(
                    '1' => array( 'state' => 'linked' ),
                    '2' => array( 'state' => 'rejected' ),
                    '4' => array( 'state' => 'linked' ),
                )
            )
        );
    }

    public function test_sorts_by_name_with_the_products_name_when_linked() {
        $sorted = Pharma_Hub_Report::sort( self::rows(), 'name', 'asc', array( '4' => 'Aspirina' ) );

        $this->assertSame( array( '3', '4', '2', '1' ), self::ids( $sorted ) );
    }

    public function test_sorts_by_difference_with_empty_values_last_both_ways() {
        $this->assertSame( array( '2', '1', '4', '3' ), self::ids( Pharma_Hub_Report::sort( self::rows(), 'difference', 'asc' ) ) );
        $this->assertSame( array( '4', '1', '2', '3' ), self::ids( Pharma_Hub_Report::sort( self::rows(), 'difference', 'desc' ) ) );
    }

    public function test_sorts_by_price() {
        $this->assertSame( array( '1', '3', '4', '2' ), self::ids( Pharma_Hub_Report::sort( self::rows(), 'store_price', 'desc' ) ) );
    }
}
