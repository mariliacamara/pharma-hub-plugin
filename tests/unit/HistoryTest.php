<?php

use PHPUnit\Framework\TestCase;

final class HistoryTest extends TestCase {

    /**
     * One comparison, as the hub sends it.
     *
     * @param string      $day    Day of October 2026.
     * @param int         $store  Store price, in cents.
     * @param int|null    $lowest Lowest price, in cents.
     * @param string|null $by     Cheapest store.
     * @param array       $extra  More fields.
     * @return array
     */
    private static function entry( $day, $store, $lowest, $by = 'Farmácia A', $extra = array() ) {
        return array_merge(
            array(
                'id'               => $day,
                'comparedAt'       => '2026-10-' . $day . 'T08:00:00.000Z',
                'outcome'          => null === $lowest ? 'only_store' : ( $store > $lowest ? 'more_expensive' : ( $store < $lowest ? 'cheapest' : 'tied' ) ),
                'storePriceCents'  => $store,
                'lowestPriceCents' => $lowest,
                'lowestStoreName'  => null === $lowest ? null : $by,
                'differenceCents'  => null === $lowest ? null : $store - $lowest,
                'storePosition'    => 3,
                'storeCount'       => 10,
            ),
            $extra
        );
    }

    public function test_puts_the_entries_in_order_and_drops_what_cannot_be_shown() {
        $usable = Pharma_Hub_History::usable(
            array(
                self::entry( '03', 900, 800 ),
                self::entry( '01', 950, 800 ),
                array( 'comparedAt' => 'not a time', 'storePriceCents' => 900 ),
                array( 'comparedAt' => '2026-10-02T08:00:00.000Z', 'storePriceCents' => '9,00' ),
                'nonsense',
                self::entry( '02', 950, 800 ),
            )
        );

        $this->assertSame( array( '01', '02', '03' ), array_column( $usable, 'id' ) );
        $this->assertSame( strtotime( '2026-10-01T08:00:00Z' ), $usable[0]['at'] );
        $this->assertSame( array(), Pharma_Hub_History::usable( null ) );
    }

    public function test_reads_missing_values_as_unknown_not_as_zero() {
        $usable = Pharma_Hub_History::usable(
            array(
                array( 'comparedAt' => '2026-10-01T08:00:00.000Z', 'storePriceCents' => 900, 'lowestPriceCents' => '800', 'storePosition' => 2.5 ),
            )
        );

        $this->assertNull( $usable[0]['lowestPriceCents'] );
        $this->assertNull( $usable[0]['differenceCents'] );
        $this->assertNull( $usable[0]['storePosition'] );
        $this->assertNull( $usable[0]['lowestStoreName'] );
    }

    public function test_keeps_only_the_days_of_the_period() {
        $entries = Pharma_Hub_History::usable( array( self::entry( '01', 900, 800 ), self::entry( '20', 900, 800 ), self::entry( '31', 900, 800 ) ) );
        $now     = strtotime( '2026-11-15T08:00:00Z' );

        $this->assertSame( array( '20', '31' ), array_column( Pharma_Hub_History::within( $entries, '30', $now ), 'id' ) );
        $this->assertSame( array( '01', '20', '31' ), array_column( Pharma_Hub_History::within( $entries, '90', $now ), 'id' ) );
        $this->assertSame( array( '01', '20', '31' ), array_column( Pharma_Hub_History::within( $entries, 'all', $now ), 'id' ) );
    }

    public function test_only_known_periods_are_accepted() {
        $this->assertSame( '90', Pharma_Hub_History::period_from( '90' ) );
        $this->assertSame( 'all', Pharma_Hub_History::period_from( 'all' ) );
        $this->assertSame( '30', Pharma_Hub_History::period_from( '7' ) );
        $this->assertSame( '30', Pharma_Hub_History::period_from( array( '90' ) ) );
        $this->assertSame( '30', Pharma_Hub_History::period_from( 90 ) );
    }

    public function test_summarises_a_period() {
        $entries = Pharma_Hub_History::usable(
            array(
                self::entry( '01', 949, 610, 'A', array( 'storePosition' => 33 ) ),
                self::entry( '02', 949, 595, 'A', array( 'storePosition' => 34 ) ),
                self::entry( '03', 590, 595, 'A', array( 'storePosition' => 1 ) ),
                self::entry( '04', 590, null, null, array( 'storePosition' => null ) ),
            )
        );

        $this->assertSame(
            array(
                'count'          => 4,
                'cheapest'       => 1,
                'min_difference' => -5,
                'max_difference' => 354,
                'best_position'  => 1,
                'worst_position' => 34,
            ),
            Pharma_Hub_History::summary( $entries )
        );
    }

    public function test_an_empty_period_has_no_numbers() {
        $summary = Pharma_Hub_History::summary( array() );

        $this->assertSame( 0, $summary['count'] );
        $this->assertNull( $summary['min_difference'] );
        $this->assertNull( $summary['best_position'] );
    }

    public function test_folds_collections_in_which_nothing_changed() {
        $entries = Pharma_Hub_History::usable(
            array(
                self::entry( '01', 949, 610 ),
                self::entry( '02', 949, 610, 'Farmácia A', array( 'storePosition' => 5 ) ),
                self::entry( '03', 949, 595 ),
                self::entry( '04', 899, 595 ),
                self::entry( '05', 899, 595 ),
                self::entry( '06', 899, 595, 'Farmácia B' ),
            )
        );

        $changes = Pharma_Hub_History::changes( $entries );

        // Newest first.
        $this->assertSame( array( '06', '05', '03', '02' ), array_map( function ( $change ) { return $change['entry']['id']; }, $changes ) );
        $this->assertSame( array( 1, 2, 1, 2 ), array_column( $changes, 'count' ) );

        // The cheapest store changed, the prices did not.
        $this->assertSame( 0, $changes[0]['store_moved'] );
        $this->assertFalse( $changes[0]['lowest_moved'] );
        $this->assertSame( 'Farmácia A', $changes[0]['previous']['lowestStoreName'] );

        // The store lowered its price.
        $this->assertSame( -1, $changes[1]['store_moved'] );
        $this->assertSame( 949, $changes[1]['previous']['storePriceCents'] );
        $this->assertSame( strtotime( '2026-10-04T08:00:00Z' ), $changes[1]['since'] );
        $this->assertSame( strtotime( '2026-10-05T08:00:00Z' ), $changes[1]['until'] );

        // The lowest price moved.
        $this->assertTrue( $changes[2]['lowest_moved'] );
        $this->assertSame( 0, $changes[2]['store_moved'] );

        // The first line has nothing before it, and shows its latest position.
        $this->assertNull( $changes[3]['previous'] );
        $this->assertSame( 5, $changes[3]['entry']['storePosition'] );
    }

    public function test_a_store_that_raised_its_price_is_told_apart() {
        $changes = Pharma_Hub_History::changes( Pharma_Hub_History::usable( array( self::entry( '01', 800, 700 ), self::entry( '02', 850, 700 ) ) ) );

        $this->assertSame( 1, $changes[0]['store_moved'] );
    }

    public function test_no_chart_without_two_moments() {
        $this->assertNull( Pharma_Hub_History::chart( array() ) );
        $this->assertNull( Pharma_Hub_History::chart( Pharma_Hub_History::usable( array( self::entry( '01', 900, 800 ) ) ) ) );
        // Two comparisons at the same instant are one moment.
        $this->assertNull( Pharma_Hub_History::chart( Pharma_Hub_History::usable( array( self::entry( '01', 900, 800 ), self::entry( '01', 900, 800 ) ) ) ) );
    }

    public function test_draws_steps_from_the_first_collection_to_the_last() {
        $chart = Pharma_Hub_History::chart(
            Pharma_Hub_History::usable(
                array(
                    self::entry( '01', 1000, 600 ),
                    self::entry( '03', 800, 600 ),
                    self::entry( '05', 800, 900 ),
                )
            )
        );

        $left  = Pharma_Hub_History::PLOT_LEFT;
        $right = Pharma_Hub_History::PLOT_RIGHT;
        $mid   = ( $left + $right ) / 2;

        // Prices 600 to 1000: a round axis with room on both sides.
        $this->assertSame( array( 500, 600, 700, 800, 900, 1000, 1100 ), array_column( $chart['y_ticks'], 'cents' ) );
        $y = function ( $cents ) use ( $chart ) {
            foreach ( $chart['y_ticks'] as $tick ) {
                if ( $tick['cents'] === $cents ) {
                    return Pharma_Hub_History::n( $tick['y'] );
                }
            }
        };

        // A price holds until the next collection, then steps.
        $this->assertSame( "M{$left},{$y( 1000 )}H{$mid}V{$y( 800 )}H{$right}V{$y( 800 )}", $chart['store_path'] );
        $this->assertSame( "M{$left},{$y( 600 )}H{$mid}V{$y( 600 )}H{$right}V{$y( 900 )}", $chart['lowest_path'] );

        // Between the lines: dearer for the first two days, still dearer for the next two.
        $this->assertSame( array( 'dearer', 'dearer' ), array_column( $chart['bands'], 'side' ) );
        $this->assertSame( (float) $left, (float) $chart['bands'][0]['x'] );

        // One mark, where the store lowered its price.
        $this->assertCount( 1, $chart['markers'] );
        $this->assertSame( -1, $chart['markers'][0]['moved'] );
        $this->assertSame( 800, $chart['markers'][0]['cents'] );
        $this->assertSame( strtotime( '2026-10-03T08:00:00Z' ), $chart['markers'][0]['at'] );

        // Both ends carry their prices; five dates along the bottom.
        $this->assertSame( 1000, $chart['first']['store_cents'] );
        $this->assertSame( 900, $chart['last']['lowest_cents'] );
        $this->assertCount( 5, $chart['x_ticks'] );
        $this->assertSame( strtotime( '2026-10-01T08:00:00Z' ), $chart['x_ticks'][0]['at'] );
        $this->assertSame( strtotime( '2026-10-05T08:00:00Z' ), $chart['x_ticks'][4]['at'] );
    }

    public function test_marks_the_stretches_in_which_the_store_was_cheaper() {
        $chart = Pharma_Hub_History::chart(
            Pharma_Hub_History::usable( array( self::entry( '01', 700, 800 ), self::entry( '02', 800, 800 ), self::entry( '03', 900, 800 ), self::entry( '04', 900, 800 ) ) )
        );

        // Cheaper, then tied (no band), then dearer.
        $this->assertSame( array( 'cheaper', 'dearer' ), array_column( $chart['bands'], 'side' ) );
    }

    public function test_breaks_the_lowest_price_line_while_no_other_store_sold_the_product() {
        $chart = Pharma_Hub_History::chart(
            Pharma_Hub_History::usable( array( self::entry( '01', 900, 800 ), self::entry( '02', 900, null ), self::entry( '03', 900, 850 ), self::entry( '04', 900, 850 ) ) )
        );

        // Two separate pieces: each starts with its own "M".
        $this->assertSame( 2, substr_count( $chart['lowest_path'], 'M' ) );
        $this->assertCount( 2, $chart['bands'] );
        $this->assertSame( 1, substr_count( $chart['store_path'], 'M' ) );
    }

    public function test_draws_the_store_alone_when_there_was_never_another_store() {
        $chart = Pharma_Hub_History::chart( Pharma_Hub_History::usable( array( self::entry( '01', 900, null ), self::entry( '02', 950, null ) ) ) );

        $this->assertSame( '', $chart['lowest_path'] );
        $this->assertSame( array(), $chart['bands'] );
        $this->assertNull( $chart['last']['lowest_y'] );
    }

    /**
     * @dataProvider scales
     */
    public function test_picks_a_round_axis_that_holds_the_prices( $min, $max, $expected ) {
        list( $low, $high, $step ) = Pharma_Hub_History::scale( $min, $max );

        $this->assertSame( $expected, array( $low, $high, $step ) );
        $this->assertLessThanOrEqual( $min, $low );
        $this->assertGreaterThan( $max, $high );
        $this->assertLessThanOrEqual( 8, ( $high - $low ) / $step );
    }

    public function scales() {
        return array(
            'a few euros apart'   => array( 592, 949, array( 500, 1000, 100 ) ),
            'one price'           => array( 899, 899, array( 898, 900, 1 ) ),
            'cents apart'         => array( 1198, 1201, array( 1197, 1202, 1 ) ),
            'on round numbers'    => array( 600, 1000, array( 500, 1100, 100 ) ),
            'a cheap product'     => array( 45, 99, array( 40, 100, 20 ) ),
            'an expensive one'    => array( 21990, 27890, array( 20000, 28000, 2000 ) ),
            'starting at nothing' => array( 0, 10, array( 0, 15, 5 ) ),
        );
    }

    public function test_writes_coordinates_with_a_point_whatever_the_language() {
        $before = setlocale( LC_NUMERIC, '0' );
        setlocale( LC_NUMERIC, 'pt_PT.UTF-8', 'pt_PT', 'de_DE.UTF-8', 'de_DE' );
        try {
            $this->assertSame( '512.3', Pharma_Hub_History::n( 512.34 ) );
            $this->assertSame( '512', Pharma_Hub_History::n( 512.0 ) );
            $this->assertSame( '0.5', Pharma_Hub_History::n( 0.5 ) );
        } finally {
            setlocale( LC_NUMERIC, $before );
        }
    }
}
