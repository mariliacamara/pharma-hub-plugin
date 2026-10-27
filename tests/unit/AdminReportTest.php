<?php

use PHPUnit\Framework\TestCase;

final class AdminReportTest extends TestCase {

    public function test_names_where_an_offer_stands_on_kuantokusta() {
        $this->assertSame( 'Com stock', Pharma_Hub_Admin_Report::offer_state_label( array( 'offerState' => 'active' ) ) );
        $this->assertSame( 'Sem stock', Pharma_Hub_Admin_Report::offer_state_label( array( 'offerState' => 'out_of_stock' ) ) );
        $this->assertSame( 'Retirada do KuantoKusta', Pharma_Hub_Admin_Report::offer_state_label( array( 'offerState' => 'delisted' ) ) );
    }

    public function test_says_nothing_when_the_hub_did_not_send_the_state() {
        $this->assertSame( '', Pharma_Hub_Admin_Report::offer_state_label( array() ) );
        $this->assertSame( '', Pharma_Hub_Admin_Report::offer_state_label( array( 'offerState' => 'something-new' ) ) );
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
