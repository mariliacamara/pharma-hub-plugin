<?php
/**
 * The price report as the plugin shows it: rows from the hub, filtered and
 * sorted for the screen and the CSV.
 *
 * The hub computes every value (difference, percentage, easy adjust, link
 * check). The plugin only reads them, picks the rows a person asked for and
 * puts them in order.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reading, filtering and sorting the report.
 */
class Pharma_Hub_Report {

    /**
     * Seconds the report is kept before asking the hub again. A collection
     * takes minutes and runs at most a few times a day.
     */
    const CACHE_SECONDS = 300;

    const OUTCOMES = array( 'cheapest', 'tied', 'more_expensive', 'only_store', 'no_data' );
    const STATES   = array( 'active', 'all' );
    const ORDERS   = array( 'name', 'store_price', 'difference', 'percent' );

    /**
     * The report for one offer state, from the cache or the hub.
     *
     * @param Pharma_Hub_Client $client Hub client.
     * @param string            $state  active or all.
     * @param bool              $fresh  Skip the cache.
     * @return array The hub's answer, with every page of rows in "rows".
     * @throws Pharma_Hub_Error When the hub refuses or cannot be reached.
     */
    public static function load( Pharma_Hub_Client $client, $state, $fresh = false ) {
        $key = 'pharma_hub_report_' . $state;
        if ( ! $fresh ) {
            $cached = get_transient( $key );
            if ( is_array( $cached ) && isset( $cached['rows'] ) ) {
                return $cached;
            }
        }
        $report = $client->get_report( $state );
        set_transient( $key, $report, self::CACHE_SECONDS );
        return $report;
    }

    /**
     * Forgets the cached report, after a collection ended.
     *
     * @return void
     */
    public static function forget() {
        foreach ( self::STATES as $state ) {
            delete_transient( 'pharma_hub_report_' . $state );
        }
    }

    /**
     * Reads the screen's filters from the query string, keeping only known values.
     *
     * @param array $query Query arguments, such as $_GET after wp_unslash.
     * @return array{state: string, outcome: string, easy: bool, check: bool, unlinked: bool, shipping: bool, orderby: string, order: string}
     */
    public static function filters_from( $query ) {
        $pick = function ( $key, $allowed, $fallback ) use ( $query ) {
            $value = isset( $query[ $key ] ) && is_string( $query[ $key ] ) ? $query[ $key ] : '';
            return in_array( $value, $allowed, true ) ? $value : $fallback;
        };
        $flag = function ( $key ) use ( $query ) {
            return isset( $query[ $key ] ) && '1' === $query[ $key ];
        };
        return array(
            'state'    => $pick( 'state', self::STATES, 'active' ),
            'outcome'  => $pick( 'outcome', self::OUTCOMES, '' ),
            'easy'     => $flag( 'easy' ),
            'check'    => $flag( 'check' ),
            'unlinked' => $flag( 'unlinked' ),
            'shipping' => $flag( 'shipping' ),
            'orderby'  => $pick( 'orderby', self::ORDERS, 'name' ),
            'order'    => $pick( 'order', array( 'asc', 'desc' ), 'asc' ),
        );
    }

    /**
     * The rows a person asked for.
     *
     * @param array[] $rows    Report rows.
     * @param array   $filters From filters_from().
     * @param array   $links   Resolved links by offer id, from Pharma_Hub_Links::resolve().
     * @return array[]
     */
    public static function filter( $rows, $filters, $links ) {
        return array_values(
            array_filter(
                $rows,
                function ( $row ) use ( $filters, $links ) {
                    if ( '' !== $filters['outcome'] && $row['outcome'] !== $filters['outcome'] ) {
                        return false;
                    }
                    if ( $filters['easy'] && empty( $row['easyAdjust'] ) ) {
                        return false;
                    }
                    if ( $filters['check'] && empty( $row['checkLink'] ) ) {
                        return false;
                    }
                    if ( $filters['unlinked'] ) {
                        $id = (string) $row['offerId'];
                        if ( isset( $links[ $id ] ) && 'linked' === $links[ $id ]['state'] ) {
                            return false;
                        }
                    }
                    return true;
                }
            )
        );
    }

    /**
     * The rows in the order a person asked for. Rows without a value for
     * the chosen column (no competitor, no data) always go last.
     *
     * @param array[] $rows    Report rows.
     * @param string  $orderby name, store_price, difference or percent.
     * @param string  $order   asc or desc.
     * @param array   $names   Name to sort by, per offer id (the product's name when linked).
     * @return array[]
     */
    public static function sort( $rows, $orderby, $order, $names = array() ) {
        $value = function ( $row ) use ( $orderby, $names ) {
            switch ( $orderby ) {
                case 'store_price':
                    return $row['storePriceCents'];
                case 'difference':
                    return $row['differenceCents'];
                case 'percent':
                    return $row['differencePercent'];
                default:
                    $id = (string) $row['offerId'];
                    return isset( $names[ $id ] ) ? $names[ $id ] : (string) $row['name'];
            }
        };
        $direction = 'desc' === $order ? -1 : 1;

        usort(
            $rows,
            function ( $a, $b ) use ( $value, $direction ) {
                $va = $value( $a );
                $vb = $value( $b );
                if ( null === $va || null === $vb ) {
                    return ( null === $va ) - ( null === $vb );
                }
                $cmp = is_string( $va ) ? strnatcasecmp( $va, $vb ) : ( $va <=> $vb );
                if ( 0 === $cmp ) {
                    // A stable tie-break, so the order never changes between visits.
                    return strcmp( (string) $a['offerId'], (string) $b['offerId'] );
                }
                return $direction * $cmp;
            }
        );
        return $rows;
    }
}
