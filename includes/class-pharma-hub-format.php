<?php
/**
 * How values from the hub are shown and exported.
 *
 * Prices arrive as integer cents (1599 = 15,99 €) and are formatted with
 * integer arithmetic only, so a price is never off by a cent. Times arrive
 * in UTC and are shown in Portugal time.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Formatting for screens and CSV, in Portuguese conventions.
 */
class Pharma_Hub_Format {

    /**
     * Portugal's time zone: every time a person sees is in it.
     */
    const TIME_ZONE = 'Europe/Lisbon';

    /**
     * Cents as an amount with a decimal comma, without the currency sign.
     *
     * 1599 → "15,99"; 123456 → "1 234,56"; -5 → "-0,05".
     *
     * @param int|null $cents  Amount in cents.
     * @param bool     $signed Prefix positive amounts with "+".
     * @param bool     $group  Group thousands; off for CSV, so a spreadsheet reads a number.
     * @return string Empty for null.
     */
    public static function amount( $cents, $signed = false, $group = true ) {
        if ( null === $cents ) {
            return '';
        }
        $cents = (int) $cents;
        $sign  = $cents < 0 ? '-' : ( $signed && $cents > 0 ? '+' : '' );
        $abs   = abs( $cents );
        // A thin, non-breaking space groups the thousands, as in Portugal.
        $euros = number_format( intdiv( $abs, 100 ), 0, ',', $group ? "\u{202F}" : '' );

        return $sign . $euros . ',' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
    }

    /**
     * Cents as a price in euros: "15,99 €".
     *
     * @param int|null $cents  Amount in cents.
     * @param bool     $signed Prefix positive amounts with "+".
     * @return string Empty for null.
     */
    public static function money( $cents, $signed = false ) {
        return null === $cents ? '' : self::amount( $cents, $signed ) . "\u{00A0}€";
    }

    /**
     * A percentage with one decimal: "+3,2 %", "-61,5 %", "0,0 %".
     *
     * @param float|int|null $percent Percentage.
     * @return string Empty for null.
     */
    public static function percent( $percent ) {
        if ( null === $percent ) {
            return '';
        }
        $tenths = (int) round( (float) $percent * 10 );
        $sign   = $tenths < 0 ? '-' : ( $tenths > 0 ? '+' : '' );
        $abs    = abs( $tenths );

        return $sign . intdiv( $abs, 10 ) . ',' . ( $abs % 10 ) . "\u{00A0}%";
    }

    /**
     * A UTC time from the hub, in Portugal time: "09/10/2026 07:31".
     *
     * @param string|null $iso    ISO 8601 time, as the hub sends it.
     * @param string      $format PHP date format.
     * @return string Empty when the value is not a time.
     */
    public static function time( $iso, $format = 'd/m/Y H:i' ) {
        if ( ! is_string( $iso ) || '' === $iso ) {
            return '';
        }
        try {
            $time = new DateTimeImmutable( $iso );
        } catch ( Exception $e ) {
            return '';
        }
        return $time->setTimezone( new DateTimeZone( self::TIME_ZONE ) )->format( $format );
    }

    /**
     * A value made safe for a CSV cell opened in a spreadsheet.
     *
     * A text that starts with =, +, -, @, a tab or a carriage return would
     * be run as a formula by Excel; it gets a leading apostrophe. Numbers
     * are formatted by the caller and passed with $is_number, so a negative
     * difference is not mistaken for a formula.
     *
     * @param mixed $value     Cell value.
     * @param bool  $is_number The value is a number formatted by the plugin.
     * @return string
     */
    public static function csv_cell( $value, $is_number = false ) {
        $text = null === $value ? '' : (string) $value;
        if ( ! $is_number && '' !== $text && false !== strpos( "=+-@\t\r", $text[0] ) ) {
            $text = "'" . $text;
        }
        return $text;
    }

    /**
     * One CSV line for Excel in Portugal: semicolons, quoted when needed.
     *
     * @param string[] $cells Cells already passed through csv_cell().
     * @return string The line, with CRLF.
     */
    public static function csv_line( $cells ) {
        $quoted = array();
        foreach ( $cells as $cell ) {
            $cell     = (string) $cell;
            $quoted[] = preg_match( '/[;"\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
        }
        return implode( ';', $quoted ) . "\r\n";
    }
}
