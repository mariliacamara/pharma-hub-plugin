<?php

use PHPUnit\Framework\TestCase;

final class AdminReportTest extends TestCase {

    public function test_only_an_offer_a_collection_compares_can_be_behind() {
        $this->assertTrue( Pharma_Hub_Admin_Report::is_behind( array( 'stale' => true, 'offerState' => 'active' ) ) );
        $this->assertFalse( Pharma_Hub_Admin_Report::is_behind( array( 'stale' => true, 'offerState' => 'out_of_stock' ) ) );
        $this->assertFalse( Pharma_Hub_Admin_Report::is_behind( array( 'stale' => true, 'offerState' => 'delisted' ) ) );
        $this->assertFalse( Pharma_Hub_Admin_Report::is_behind( array( 'stale' => false, 'offerState' => 'active' ) ) );
        // An older hub does not send the state: the note stays.
        $this->assertTrue( Pharma_Hub_Admin_Report::is_behind( array( 'stale' => true ) ) );
    }

    public function test_tells_an_offer_never_compared_from_a_page_that_could_not_be_read() {
        $this->assertSame(
            'Ainda não comparada',
            Pharma_Hub_Admin_Report::situation_label( array( 'outcome' => 'no_data', 'runId' => null, 'comparedAt' => null ) )
        );
        $this->assertSame(
            'Sem dados',
            Pharma_Hub_Admin_Report::situation_label( array( 'outcome' => 'no_data', 'runId' => 'run-1', 'comparedAt' => '2026-10-27T09:00:00.000Z' ) )
        );
    }

    public function test_keeps_the_outcome_names_for_compared_offers() {
        $this->assertSame( 'Mais cara', Pharma_Hub_Admin_Report::situation_label( array( 'outcome' => 'more_expensive', 'runId' => 'run-1' ) ) );
        // An older hub does not send runId at all: the outcome is all there is.
        $this->assertSame( 'Empatada', Pharma_Hub_Admin_Report::situation_label( array( 'outcome' => 'tied' ) ) );
        $this->assertSame( 'new_outcome', Pharma_Hub_Admin_Report::situation_label( array( 'outcome' => 'new_outcome', 'runId' => 'run-1' ) ) );
    }
}
