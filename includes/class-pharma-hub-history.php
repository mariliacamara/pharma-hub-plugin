<?php
/**
 * The history of one offer as the plugin shows it: the hub's comparisons,
 * cut to a period, summarised, folded into the moments when something
 * changed, and laid out as a step chart.
 *
 * The hub computes every value of a comparison. This class only picks,
 * counts and places them. Pure logic: it is tested without WordPress.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reading the history of one offer.
 */
class Pharma_Hub_History {

    /**
     * Periods a person can choose, in days. Zero is everything.
     */
    const PERIODS = array(
        '30'  => 30,
        '90'  => 90,
        'all' => 0,
    );

    const DEFAULT_PERIOD = '30';

    /**
     * The chart's drawing area, in the units of its viewBox.
     */
    const CHART_WIDTH  = 1000;
    const CHART_HEIGHT = 300;
    const PLOT_LEFT    = 64;
    const PLOT_RIGHT   = 960;
    const PLOT_TOP     = 24;
    const PLOT_BOTTOM  = 250;

    /**
     * The period asked for, or the default when it is not one.
     *
     * @param mixed $value Value from a request.
     * @return string A key of PERIODS.
     */
    public static function period_from( $value ) {
        return is_string( $value ) && isset( self::PERIODS[ $value ] ) ? $value : self::DEFAULT_PERIOD;
    }

    /**
     * The entries that can be shown, oldest first.
     *
     * An entry needs a time that can be read and the store's price. The
     * hub sends them newest first; the order here does not depend on that.
     *
     * @param mixed $entries Entries from the hub.
     * @return array[] Entries, each with "at" (Unix time) added.
     */
    public static function usable( $entries ) {
        $usable = array();
        foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
            if ( ! is_array( $entry ) || ! isset( $entry['comparedAt'], $entry['storePriceCents'] ) || ! is_int( $entry['storePriceCents'] ) ) {
                continue;
            }
            $at = is_string( $entry['comparedAt'] ) ? strtotime( $entry['comparedAt'] ) : false;
            if ( false === $at ) {
                continue;
            }
            $entry['at'] = $at;
            foreach ( array( 'lowestPriceCents', 'differenceCents', 'storePosition', 'storeCount' ) as $key ) {
                $entry[ $key ] = isset( $entry[ $key ] ) && is_int( $entry[ $key ] ) ? $entry[ $key ] : null;
            }
            $entry['lowestStoreName'] = isset( $entry['lowestStoreName'] ) && is_string( $entry['lowestStoreName'] ) ? $entry['lowestStoreName'] : null;
            $entry['outcome']         = isset( $entry['outcome'] ) && is_string( $entry['outcome'] ) ? $entry['outcome'] : '';
            $usable[]                 = $entry;
        }
        usort(
            $usable,
            function ( $a, $b ) {
                return $a['at'] - $b['at'];
            }
        );
        return $usable;
    }

    /**
     * The entries of the last days of a period.
     *
     * @param array[] $entries From usable().
     * @param string  $period  A key of PERIODS.
     * @param int     $now     Unix time.
     * @return array[]
     */
    public static function within( $entries, $period, $now ) {
        $days = isset( self::PERIODS[ $period ] ) ? self::PERIODS[ $period ] : 0;
        if ( 0 === $days ) {
            return $entries;
        }
        $from = $now - $days * 86400;
        return array_values(
            array_filter(
                $entries,
                function ( $entry ) use ( $from ) {
                    return $entry['at'] >= $from;
                }
            )
        );
    }

    /**
     * What the period looked like, in a few numbers.
     *
     * @param array[] $entries From usable().
     * @return array{count: int, cheapest: int, min_difference: int|null, max_difference: int|null, best_position: int|null, worst_position: int|null}
     */
    public static function summary( $entries ) {
        $summary = array(
            'count'          => count( $entries ),
            'cheapest'       => 0,
            'min_difference' => null,
            'max_difference' => null,
            'best_position'  => null,
            'worst_position' => null,
        );
        foreach ( $entries as $entry ) {
            if ( 'cheapest' === $entry['outcome'] ) {
                ++$summary['cheapest'];
            }
            if ( null !== $entry['differenceCents'] ) {
                $summary['min_difference'] = null === $summary['min_difference'] ? $entry['differenceCents'] : min( $summary['min_difference'], $entry['differenceCents'] );
                $summary['max_difference'] = null === $summary['max_difference'] ? $entry['differenceCents'] : max( $summary['max_difference'], $entry['differenceCents'] );
            }
            if ( null !== $entry['storePosition'] ) {
                $summary['best_position']  = null === $summary['best_position'] ? $entry['storePosition'] : min( $summary['best_position'], $entry['storePosition'] );
                $summary['worst_position'] = null === $summary['worst_position'] ? $entry['storePosition'] : max( $summary['worst_position'], $entry['storePosition'] );
            }
        }
        return $summary;
    }

    /**
     * The moments when something changed, newest first.
     *
     * Collections in a row with the same store price, the same lowest price
     * and the same cheapest store are one line: thirty equal lines tell a
     * person nothing. The position among the stores moves on its own as
     * other stores change their prices, so it does not start a line; each
     * line shows the position of its latest collection.
     *
     * @param array[] $entries From usable().
     * @return array[] Each: since, until (Unix times), count, entry (the latest
     *                 of the run), previous (the entry before the run, or null),
     *                 store_moved (-1 down, 0 same, 1 up), lowest_moved (bool).
     */
    public static function changes( $entries ) {
        $changes  = array();
        $current  = null;
        $previous = null;
        foreach ( $entries as $entry ) {
            if ( null !== $current && self::same_prices( $current['entry'], $entry ) ) {
                $current['until'] = $entry['at'];
                $current['entry'] = $entry;
                ++$current['count'];
                $previous = $entry;
                continue;
            }
            if ( null !== $current ) {
                $changes[] = $current;
            }
            $current = array(
                'since'        => $entry['at'],
                'until'        => $entry['at'],
                'count'        => 1,
                'entry'        => $entry,
                'previous'     => $previous,
                'store_moved'  => null === $previous ? 0 : ( $entry['storePriceCents'] <=> $previous['storePriceCents'] ),
                'lowest_moved' => null !== $previous && $entry['lowestPriceCents'] !== $previous['lowestPriceCents'],
            );
            $previous = $entry;
        }
        if ( null !== $current ) {
            $changes[] = $current;
        }
        return array_reverse( $changes );
    }

    /**
     * Whether two collections show the same prices and the same cheapest store.
     *
     * @param array $a Entry.
     * @param array $b Entry.
     * @return bool
     */
    private static function same_prices( $a, $b ) {
        return $a['storePriceCents'] === $b['storePriceCents']
            && $a['lowestPriceCents'] === $b['lowestPriceCents']
            && $a['lowestStoreName'] === $b['lowestStoreName'];
    }

    /**
     * The step chart of the store's price against the lowest price.
     *
     * A price holds from its collection until the next one, so both lines
     * are steps. Nothing is drawn after the last collection: what happened
     * since is not known. Between the lines, each stretch is a band that
     * says whether the store was dearer or cheaper.
     *
     * @param array[] $entries From usable().
     * @return array|null Null when there are fewer than two moments to draw.
     *         Otherwise: from, to (Unix times), y_ticks (cents and y), x_ticks
     *         (time and x), store_path, lowest_path (SVG path data), bands (x,
     *         y, width, height, side), markers (x, y, at, cents, moved) where
     *         the store changed its price, and first and last (x, store_y,
     *         lowest_y, store_cents, lowest_cents).
     */
    public static function chart( $entries ) {
        $count = count( $entries );
        if ( $count < 2 || $entries[ $count - 1 ]['at'] <= $entries[0]['at'] ) {
            return null;
        }
        $from = $entries[0]['at'];
        $to   = $entries[ $count - 1 ]['at'];

        $prices = array();
        foreach ( $entries as $entry ) {
            $prices[] = $entry['storePriceCents'];
            if ( null !== $entry['lowestPriceCents'] ) {
                $prices[] = $entry['lowestPriceCents'];
            }
        }
        list( $low, $high, $step ) = self::scale( min( $prices ), max( $prices ) );

        $x = function ( $at ) use ( $from, $to ) {
            return self::PLOT_LEFT + ( $at - $from ) / ( $to - $from ) * ( self::PLOT_RIGHT - self::PLOT_LEFT );
        };
        $y = function ( $cents ) use ( $low, $high ) {
            return self::PLOT_BOTTOM - ( $cents - $low ) / ( $high - $low ) * ( self::PLOT_BOTTOM - self::PLOT_TOP );
        };

        $store   = '';
        $lowest  = '';
        $drawing = false;
        $bands   = array();
        $markers = array();
        foreach ( $entries as $i => $entry ) {
            $at_x    = $x( $entry['at'] );
            $next_x  = $i + 1 < $count ? $x( $entries[ $i + 1 ]['at'] ) : $at_x;
            $store_y = $y( $entry['storePriceCents'] );

            $store .= 0 === $i ? 'M' . self::n( $at_x ) . ',' . self::n( $store_y ) : 'V' . self::n( $store_y );
            if ( $next_x > $at_x ) {
                $store .= 'H' . self::n( $next_x );
            }
            if ( $i > 0 && $entry['storePriceCents'] !== $entries[ $i - 1 ]['storePriceCents'] ) {
                $markers[] = array(
                    'x'     => $at_x,
                    'y'     => $store_y,
                    'at'    => $entry['at'],
                    'cents' => $entry['storePriceCents'],
                    'moved' => $entry['storePriceCents'] <=> $entries[ $i - 1 ]['storePriceCents'],
                );
            }

            if ( null === $entry['lowestPriceCents'] ) {
                $drawing = false;
                continue;
            }
            $lowest_y = $y( $entry['lowestPriceCents'] );
            $lowest  .= $drawing ? 'V' . self::n( $lowest_y ) : 'M' . self::n( $at_x ) . ',' . self::n( $lowest_y );
            $drawing  = true;
            if ( $next_x > $at_x ) {
                $lowest .= 'H' . self::n( $next_x );
                if ( $entry['storePriceCents'] !== $entry['lowestPriceCents'] ) {
                    $bands[] = array(
                        'x'      => $at_x,
                        'y'      => min( $store_y, $lowest_y ),
                        'width'  => $next_x - $at_x,
                        'height' => abs( $store_y - $lowest_y ),
                        'side'   => $entry['storePriceCents'] > $entry['lowestPriceCents'] ? 'dearer' : 'cheaper',
                    );
                }
            }
        }

        $y_ticks = array();
        for ( $cents = $low; $cents <= $high; $cents += $step ) {
            $y_ticks[] = array(
                'cents' => $cents,
                'y'     => $y( $cents ),
            );
        }
        $x_ticks = array();
        for ( $i = 0; $i <= 4; $i++ ) {
            $at        = (int) round( $from + ( $to - $from ) * $i / 4 );
            $x_ticks[] = array(
                'at' => $at,
                'x'  => $x( $at ),
            );
        }

        $end = function ( $entry ) use ( $x, $y ) {
            return array(
                'x'            => $x( $entry['at'] ),
                'store_y'      => $y( $entry['storePriceCents'] ),
                'lowest_y'     => null === $entry['lowestPriceCents'] ? null : $y( $entry['lowestPriceCents'] ),
                'store_cents'  => $entry['storePriceCents'],
                'lowest_cents' => $entry['lowestPriceCents'],
            );
        };

        return array(
            'from'        => $from,
            'to'          => $to,
            'y_ticks'     => $y_ticks,
            'x_ticks'     => $x_ticks,
            'store_path'  => $store,
            'lowest_path' => $lowest,
            'bands'       => $bands,
            'markers'     => $markers,
            'first'       => $end( $entries[0] ),
            'last'        => $end( $entries[ $count - 1 ] ),
        );
    }

    /**
     * A price axis that holds a range of prices, with round steps.
     *
     * @param int $min Lowest price, in cents.
     * @param int $max Highest price, in cents.
     * @return int[] array( bottom, top, step ), in cents.
     */
    public static function scale( $min, $max ) {
        $span = max( 1, $max - $min );
        // About four steps, each 1, 2 or 5 times a power of ten cents.
        $raw   = $span / 4;
        $power = pow( 10, floor( log10( max( 1, $raw ) ) ) );
        $step  = (int) $power;
        foreach ( array( 1, 2, 5, 10 ) as $factor ) {
            if ( $factor * $power >= $raw ) {
                $step = (int) ( $factor * $power );
                break;
            }
        }
        $step = max( 1, $step );
        $low  = (int) ( floor( $min / $step ) * $step );
        $high = (int) ( ceil( $max / $step ) * $step );
        if ( $high === $low ) {
            $high = $low + $step;
        }
        // Room above and below, so a line never sits on the frame.
        if ( $low === $min && $low >= $step ) {
            $low -= $step;
        }
        if ( $high === $max ) {
            $high += $step;
        }
        return array( $low, $high, $step );
    }

    /**
     * A coordinate as text, with a point whatever the site's language is.
     *
     * @param float $value Coordinate.
     * @return string
     */
    public static function n( $value ) {
        $text = number_format( (float) $value, 1, '.', '' );
        return '.0' === substr( $text, -2 ) ? substr( $text, 0, -2 ) : $text;
    }
}
