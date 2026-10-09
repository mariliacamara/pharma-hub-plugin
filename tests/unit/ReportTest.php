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
                'orderby'  => 'priority',
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

    public function test_puts_what_can_be_acted_on_first_by_default() {
        $rows = array(
            self::row( 1, 'A', 'only_store', 1000 ),
            self::row( 2, 'B', 'cheapest', 900, -500 ),
            self::row( 3, 'C', 'more_expensive', 1000, 300 ),
            self::row( 4, 'D', 'no_data', 1000 ),
            self::row( 5, 'E', 'more_expensive', 1010, 10, array( 'easyAdjust' => true ) ),
            self::row( 6, 'F', 'more_expensive', 1000, 45 ),
            self::row( 7, 'G', 'tied', 1000, 0 ),
            self::row( 8, 'H', 'cheapest', 900, -7 ),
            self::row( 9, 'I', 'more_expensive', 1003, 3, array( 'easyAdjust' => true ) ),
        );

        // Easy adjusts, more expensive, tied, cheapest, only store, no data;
        // inside each group the smallest gap first.
        $this->assertSame( array( '9', '5', '6', '3', '7', '8', '2', '1', '4' ), self::ids( Pharma_Hub_Report::sort( $rows, 'priority', 'asc' ) ) );
        // The direction does not apply to this order.
        $this->assertSame( array( '9', '5', '6', '3', '7', '8', '2', '1', '4' ), self::ids( Pharma_Hub_Report::sort( $rows, 'priority', 'desc' ) ) );
    }

    public function test_suggests_one_cent_under_the_lowest_price_only_for_an_easy_adjust() {
        $easy = array( 'easyAdjust' => true, 'lowestPriceCents' => 1198 );

        $this->assertSame( 1197, Pharma_Hub_Report::price_to_be_cheapest( $easy ) );
        $this->assertNull( Pharma_Hub_Report::price_to_be_cheapest( array( 'easyAdjust' => false, 'lowestPriceCents' => 1198 ) ) );
        $this->assertNull( Pharma_Hub_Report::price_to_be_cheapest( array( 'easyAdjust' => true, 'lowestPriceCents' => null ) ) );
        $this->assertNull( Pharma_Hub_Report::price_to_be_cheapest( array( 'easyAdjust' => true, 'lowestPriceCents' => 1 ) ) );
        $this->assertNull( Pharma_Hub_Report::price_to_be_cheapest( array( 'easyAdjust' => true, 'lowestPriceCents' => '1198' ) ) );
    }

    public function test_places_the_position_marker_between_the_cheapest_and_the_dearest() {
        $this->assertSame( 0, Pharma_Hub_Report::position_percent( 1, 35 ) );
        $this->assertSame( 100, Pharma_Hub_Report::position_percent( 3, 3 ) );
        $this->assertSame( 5, Pharma_Hub_Report::position_percent( 2, 21 ) );
        $this->assertSame( 67, Pharma_Hub_Report::position_percent( 30, 44 ) );
        // Never outside the track, whatever the hub sends.
        $this->assertSame( 100, Pharma_Hub_Report::position_percent( 9, 3 ) );
        // No marker without another store, or without a position.
        $this->assertNull( Pharma_Hub_Report::position_percent( 1, 1 ) );
        $this->assertNull( Pharma_Hub_Report::position_percent( null, 5 ) );
        $this->assertNull( Pharma_Hub_Report::position_percent( 0, 5 ) );
        $this->assertNull( Pharma_Hub_Report::position_percent( '2', 5 ) );
    }
}
