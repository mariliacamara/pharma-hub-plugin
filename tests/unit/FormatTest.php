<?php

use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase {

    const NBSP   = "\u{00A0}";
    const THIN   = "\u{202F}";

    public function test_formats_cents_without_floating_point() {
        $this->assertSame( '15,99' . self::NBSP . '€', Pharma_Hub_Format::money( 1599 ) );
        $this->assertSame( '0,05' . self::NBSP . '€', Pharma_Hub_Format::money( 5 ) );
        $this->assertSame( '-0,05' . self::NBSP . '€', Pharma_Hub_Format::money( -5 ) );
        $this->assertSame( '+6,05' . self::NBSP . '€', Pharma_Hub_Format::money( 605, true ) );
        $this->assertSame( '0,00' . self::NBSP . '€', Pharma_Hub_Format::money( 0, true ) );
        $this->assertSame( '1' . self::THIN . '234,56', Pharma_Hub_Format::amount( 123456 ) );
        $this->assertSame( '1234,56', Pharma_Hub_Format::amount( 123456, false, false ) );
        $this->assertSame( '', Pharma_Hub_Format::money( null ) );
    }

    public function test_formats_percentages_with_one_decimal_and_sign() {
        $this->assertSame( '+10,3' . self::NBSP . '%', Pharma_Hub_Format::percent( 10.2541 ) );
        $this->assertSame( '-61,5' . self::NBSP . '%', Pharma_Hub_Format::percent( -61.538 ) );
        $this->assertSame( '+0,5' . self::NBSP . '%', Pharma_Hub_Format::percent( 0.4975 ) );
        $this->assertSame( '0,0' . self::NBSP . '%', Pharma_Hub_Format::percent( 0 ) );
        $this->assertSame( '0,0' . self::NBSP . '%', Pharma_Hub_Format::percent( -0.01 ) );
        $this->assertSame( '', Pharma_Hub_Format::percent( null ) );
    }

    public function test_shows_hub_times_in_portugal_time() {
        // Summer time: UTC+1.
        $this->assertSame( '09/10/2026 07:31', Pharma_Hub_Format::time( '2026-10-09T06:31:12.000Z' ) );
        // Winter time: UTC.
        $this->assertSame( '15/01/2027 06:31', Pharma_Hub_Format::time( '2027-01-15T06:31:00Z' ) );
        $this->assertSame( '', Pharma_Hub_Format::time( 'not a time' ) );
        $this->assertSame( '', Pharma_Hub_Format::time( null ) );
    }

    public function test_keeps_spreadsheets_from_running_text_as_a_formula() {
        $this->assertSame( "'=HYPERLINK(\"x\")", Pharma_Hub_Format::csv_cell( '=HYPERLINK("x")' ) );
        $this->assertSame( "'+351", Pharma_Hub_Format::csv_cell( '+351' ) );
        $this->assertSame( "'-x", Pharma_Hub_Format::csv_cell( '-x' ) );
        $this->assertSame( "'@SUM(A1)", Pharma_Hub_Format::csv_cell( '@SUM(A1)' ) );
        $this->assertSame( "'\tx", Pharma_Hub_Format::csv_cell( "\tx" ) );
        $this->assertSame( 'Farmácia B', Pharma_Hub_Format::csv_cell( 'Farmácia B' ) );
        $this->assertSame( '-0,05', Pharma_Hub_Format::csv_cell( '-0,05', true ) );
        $this->assertSame( '', Pharma_Hub_Format::csv_cell( null ) );
    }

    public function test_writes_csv_lines_for_excel_in_portugal() {
        $this->assertSame( "a;15,99;b\r\n", Pharma_Hub_Format::csv_line( array( 'a', '15,99', 'b' ) ) );
        $this->assertSame( "\"a;b\";\"say \"\"hi\"\"\";\"x\ny\"\r\n", Pharma_Hub_Format::csv_line( array( 'a;b', 'say "hi"', "x\ny" ) ) );
    }
}
