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
    const STATES   = array( 'all', 'active' );
    const ORDERS   = array( 'priority', 'name', 'store_price', 'difference', 'percent' );

    /**
     * How many rows a page of the table can hold, and how many it holds
     * until the person picks another number.
     */
    const PER_PAGE_CHOICES = array( 10, 25, 50, 100 );
    const DEFAULT_PER_PAGE = 10;

    /**
     * Longest search text kept, in characters.
     */
    const MAX_SEARCH = 100;

    /**
     * The report for one offer state, from the cache or the hub.
     *
     * @param Pharma_Hub_Client $client Hub client.
     * @param string            $state  all (every synced offer) or active (listed and in stock).
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
     * @return array{state: string, outcome: string, easy: bool, check: bool, unlinked: bool, shipping: bool, search: string, orderby: string, order: string, per_page: int, page: int}
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
            'state'    => $pick( 'state', self::STATES, 'all' ),
            'outcome'  => $pick( 'outcome', self::OUTCOMES, '' ),
            'easy'     => $flag( 'easy' ),
            'check'    => $flag( 'check' ),
            'unlinked' => $flag( 'unlinked' ),
            'shipping' => $flag( 'shipping' ),
            'search'   => self::clean_search( isset( $query['s'] ) ? $query['s'] : '' ),
            'orderby'  => $pick( 'orderby', self::ORDERS, 'priority' ),
            'order'    => $pick( 'order', array( 'asc', 'desc' ), 'asc' ),
            'per_page' => self::per_page_from( isset( $query['per_page'] ) ? $query['per_page'] : null ),
            'page'     => self::page_from( isset( $query['paged'] ) ? $query['paged'] : null ),
        );
    }

    /**
     * A page size from a request: one of the offered sizes, or the default.
     *
     * @param mixed $value Value from a request.
     * @return int
     */
    public static function per_page_from( $value ) {
        $number = is_string( $value ) && ctype_digit( $value ) ? (int) $value : ( is_int( $value ) ? $value : 0 );
        return in_array( $number, self::PER_PAGE_CHOICES, true ) ? $number : self::DEFAULT_PER_PAGE;
    }

    /**
     * A page number from a request: a whole number from 1 up, or 1.
     *
     * @param mixed $value Value from a request.
     * @return int
     */
    public static function page_from( $value ) {
        $number = is_string( $value ) && ctype_digit( $value ) && strlen( $value ) <= 9 ? (int) $value : ( is_int( $value ) ? $value : 1 );
        return max( 1, $number );
    }

    /**
     * One page of the rows.
     *
     * A page past the end lands on the last page, so a link kept from before
     * a smaller report never shows an empty table.
     *
     * @param array[] $rows     Filtered and sorted rows.
     * @param int     $page     Page asked for, from 1.
     * @param int     $per_page Rows per page.
     * @return array{rows: array[], page: int, pages: int, total: int, from: int, to: int} "from" and "to" count rows from 1; both are 0 when there are none.
     */
    public static function paginate( $rows, $page, $per_page ) {
        $per_page = max( 1, (int) $per_page );
        $total    = count( $rows );
        $pages    = max( 1, (int) ceil( $total / $per_page ) );
        $page     = min( max( 1, (int) $page ), $pages );
        $slice    = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
        $from     = $total > 0 ? ( $page - 1 ) * $per_page + 1 : 0;
        return array(
            'rows'  => $slice,
            'page'  => $page,
            'pages' => $pages,
            'total' => $total,
            'from'  => $from,
            'to'    => $total > 0 ? $from + count( $slice ) - 1 : 0,
        );
    }

    /**
     * A search text from a request, as one short line without tags.
     *
     * @param mixed $text Value from a request.
     * @return string
     */
    public static function clean_search( $text ) {
        if ( ! is_string( $text ) ) {
            return '';
        }
        $text = trim( (string) preg_replace( '/[\s\p{Cc}]+/u', ' ', wp_strip_all_tags( $text ) ) );
        return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_SEARCH, 'UTF-8' ) : substr( $text, 0, self::MAX_SEARCH );
    }

    /**
     * What the search looks at for one row: the offer's name, REF and EAN,
     * and the name, REF and brands of its product in the store. Folded, so
     * case and accents do not matter.
     *
     * @param array    $row     Report row.
     * @param array    $product The product's name and sku, or an empty array when the offer has no link.
     * @param string[] $brands  The product's brands.
     * @return string[]
     */
    public static function searchable( $row, $product = array(), $brands = array() ) {
        $texts = array(
            isset( $row['name'] ) ? $row['name'] : '',
            isset( $row['sku'] ) ? $row['sku'] : '',
            isset( $row['ean'] ) ? $row['ean'] : '',
            isset( $product['name'] ) ? $product['name'] : '',
            isset( $product['sku'] ) ? $product['sku'] : '',
        );
        $texts = array_filter(
            array_merge( $texts, (array) $brands ),
            function ( $text ) {
                return is_scalar( $text ) && '' !== (string) $text;
            }
        );
        return array_values( array_map( array( 'Pharma_Hub_Linker', 'fold' ), $texts ) );
    }

    /**
     * The rows a person asked for.
     *
     * @param array[] $rows    Report rows.
     * @param array   $filters From filters_from().
     * @param array   $links   Resolved links by offer id, from Pharma_Hub_Links::resolve().
     * @param array   $texts   What the search looks at, by offer id, from searchable().
     * @return array[]
     */
    public static function filter( $rows, $filters, $links, $texts = array() ) {
        $term = isset( $filters['search'] ) ? Pharma_Hub_Linker::fold( $filters['search'] ) : '';
        return array_values(
            array_filter(
                $rows,
                function ( $row ) use ( $filters, $links, $texts, $term ) {
                    if ( '' !== $filters['outcome'] && $row['outcome'] !== $filters['outcome'] ) {
                        return false;
                    }
                    if ( $filters['easy'] && empty( $row['easyAdjust'] ) ) {
                        return false;
                    }
                    if ( $filters['check'] && empty( $row['checkLink'] ) ) {
                        return false;
                    }
                    $id = (string) $row['offerId'];
                    if ( $filters['unlinked'] && isset( $links[ $id ] ) && 'linked' === $links[ $id ]['state'] ) {
                        return false;
                    }
                    if ( '' !== $term ) {
                        $searchable = isset( $texts[ $id ] ) ? $texts[ $id ] : self::searchable( $row );
                        if ( ! Pharma_Hub_Linker::matches_search( $term, $searchable ) ) {
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
     * @param string  $orderby priority, name, store_price, difference or percent.
     * @param string  $order   asc or desc; not used by priority.
     * @param array   $names   Name to sort by, per offer id (the product's name when linked).
     * @return array[]
     */
    public static function sort( $rows, $orderby, $order, $names = array() ) {
        if ( 'priority' === $orderby ) {
            return self::sort_by_priority( $rows );
        }
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

    /**
     * The rows with what a person can act on first.
     *
     * Easy adjusts come first, then the offers where the store is more
     * expensive, then ties, then where it is the cheapest, then where it is
     * the only store, and last the offers without data. Inside each group
     * the smallest gap comes first: the closer the prices, the less it
     * takes to change the outcome.
     *
     * @param array[] $rows Report rows.
     * @return array[]
     */
    public static function sort_by_priority( $rows ) {
        $rank = function ( $row ) {
            if ( ! empty( $row['easyAdjust'] ) ) {
                return 0;
            }
            $ranks = array(
                'more_expensive' => 1,
                'tied'           => 2,
                'cheapest'       => 3,
                'only_store'     => 4,
            );
            return isset( $ranks[ $row['outcome'] ] ) ? $ranks[ $row['outcome'] ] : 5;
        };
        $gap  = function ( $row ) {
            return isset( $row['differenceCents'] ) && is_numeric( $row['differenceCents'] ) ? abs( $row['differenceCents'] ) : PHP_INT_MAX;
        };

        usort(
            $rows,
            function ( $a, $b ) use ( $rank, $gap ) {
                $cmp = $rank( $a ) <=> $rank( $b );
                if ( 0 === $cmp ) {
                    $cmp = $gap( $a ) <=> $gap( $b );
                }
                // A stable tie-break, so the order never changes between visits.
                return 0 !== $cmp ? $cmp : strcmp( (string) $a['offerId'], (string) $b['offerId'] );
            }
        );
        return $rows;
    }

    /**
     * The price that would make an easy adjust the cheapest: one cent under
     * the lowest price of the other stores.
     *
     * Arithmetic on two numbers the hub already sent, shown as a hint. It
     * says nothing about cost or margin: whether to change the price is the
     * store's decision.
     *
     * @param array $row Report row.
     * @return int|null Cents, or null when the row is not an easy adjust.
     */
    public static function price_to_be_cheapest( $row ) {
        if ( empty( $row['easyAdjust'] ) || ! isset( $row['lowestPriceCents'] ) || ! is_int( $row['lowestPriceCents'] ) ) {
            return null;
        }
        return $row['lowestPriceCents'] > 1 ? $row['lowestPriceCents'] - 1 : null;
    }

    /**
     * Where the store stands among the stores of a page, from 0 (the
     * cheapest) to 100 (the most expensive), for the position marker.
     *
     * @param mixed $position 1 for the cheapest.
     * @param mixed $count    Number of stores.
     * @return int|null Null when there is no position, or no other store.
     */
    public static function position_percent( $position, $count ) {
        if ( ! is_int( $position ) || ! is_int( $count ) || $count < 2 || $position < 1 ) {
            return null;
        }
        return (int) round( 100 * ( min( $position, $count ) - 1 ) / ( $count - 1 ) );
    }
}
