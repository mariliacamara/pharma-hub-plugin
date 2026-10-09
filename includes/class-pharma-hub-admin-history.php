<?php
/**
 * The history of one offer, opened from a row of the report: the store's
 * price against the lowest price over time, a few numbers about the period
 * and the moments when something changed.
 *
 * The chart is an SVG drawn here, in PHP: no script and no library, so it
 * needs nothing from the browser and adds nothing to maintain.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the history of one offer.
 */
class Pharma_Hub_Admin_History {

    /**
     * The offer asked for in the address, or an empty string.
     *
     * @return string
     */
    public static function asked_offer_id() {
        $offer_id = isset( $_GET['history'] ) ? sanitize_text_field( wp_unslash( $_GET['history'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks what to show.
        return Pharma_Hub_Client::is_offer_id( $offer_id ) ? $offer_id : '';
    }

    /**
     * Renders the screen.
     *
     * @param string $offer_id Hub offer id, already checked.
     * @return void
     */
    public static function render( $offer_id ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only known values are kept.
        $filters = Pharma_Hub_Report::filters_from( wp_unslash( $_GET ) );
        $period  = Pharma_Hub_History::period_from( isset( $_GET['period'] ) ? wp_unslash( $_GET['period'] ) : '' );
        // phpcs:enable
        printf(
            '<p class="pharma-hub-back"><a href="%s">&larr; %s</a></p>',
            esc_url( Pharma_Hub_Admin_Report::url( $filters ) ),
            esc_html__( 'Voltar ao relatório', 'pharma-hub-plugin' )
        );

        $client = Pharma_Hub_Settings::client();
        if ( null === $client ) {
            printf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__( 'Ainda não está ligado ao hub.', 'pharma-hub-plugin' ),
                esc_url( Pharma_Hub_Admin::connection_url() ),
                esc_html__( 'Configurar a ligação', 'pharma-hub-plugin' )
            );
            return;
        }

        try {
            // Every offer, with or without stock: a history is worth reading
            // for an offer that left the report too.
            $report  = Pharma_Hub_Report::load( $client, 'all' );
            $row     = self::find_row( $report, $offer_id );
            $history = null === $row ? null : $client->get_offer_history( $offer_id );
        } catch ( Pharma_Hub_Error $error ) {
            printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( Pharma_Hub_Admin::error_message( $error ) ) );
            return;
        }
        if ( null === $row ) {
            printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html__( 'Esta oferta não está entre as ofertas da loja no hub.', 'pharma-hub-plugin' ) );
            return;
        }

        $all     = Pharma_Hub_History::usable( $history['entries'] );
        $entries = Pharma_Hub_History::within( $all, $period, time() );

        self::render_head( $row, self::product_of( $row ) );
        self::render_chart_panel( $entries, $period, $filters, $offer_id, count( $all ) );
        if ( $entries ) {
            self::render_summary( Pharma_Hub_History::summary( $entries ) );
            self::render_changes( Pharma_Hub_History::changes( $entries ), count( $entries ) );
        }
        if ( ! $history['complete'] ) {
            printf(
                '<p class="description">%s</p>',
                esc_html(
                    sprintf(
                        /* translators: %d: number of collections */
                        __( 'São mostradas as %d coletas mais recentes desta oferta.', 'pharma-hub-plugin' ),
                        count( $all )
                    )
                )
            );
        }
    }

    /**
     * The report's row of an offer.
     *
     * @param array  $report   Report from the hub.
     * @param string $offer_id Hub offer id.
     * @return array|null
     */
    private static function find_row( $report, $offer_id ) {
        foreach ( isset( $report['rows'] ) && is_array( $report['rows'] ) ? $report['rows'] : array() as $row ) {
            if ( isset( $row['offerId'] ) && (string) $row['offerId'] === $offer_id ) {
                return $row;
            }
        }
        return null;
    }

    /**
     * The WooCommerce product linked to the offer of a row, if any.
     *
     * @param array $row Report row.
     * @return WC_Product|null
     */
    private static function product_of( $row ) {
        $offer_id = (string) $row['offerId'];
        $links    = Pharma_Hub_Links::resolve(
            array(
                array(
                    'id'       => $offer_id,
                    'sku'      => $row['sku'],
                    'ean'      => $row['ean'],
                    'storeUrl' => $row['storeUrl'],
                ),
            )
        );
        if ( ! isset( $links[ $offer_id ] ) || 'linked' !== $links[ $offer_id ]['state'] ) {
            return null;
        }
        $product = wc_get_product( $links[ $offer_id ]['product_id'] );
        return $product ? $product : null;
    }

    /**
     * The product and how it stands today.
     *
     * @param array           $row     Report row: the offer's latest comparison.
     * @param WC_Product|null $product Linked product.
     * @return void
     */
    private static function render_head( $row, $product ) {
        $labels     = Pharma_Hub_Admin_Report::outcome_labels();
        $difference = $row['differenceCents'];
        $direction  = null === $difference || 0 === $difference ? '' : ( $difference > 0 ? 'is-up' : 'is-down' );
        $ids        = array();
        if ( ! empty( $row['sku'] ) ) {
            /* translators: %s: SKU */
            $ids[] = esc_html( sprintf( __( 'REF %s', 'pharma-hub-plugin' ), $row['sku'] ) );
        }
        if ( ! empty( $row['ean'] ) ) {
            /* translators: %s: EAN */
            $ids[] = esc_html( sprintf( __( 'EAN %s', 'pharma-hub-plugin' ), $row['ean'] ) );
        }
        $ids[] = sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( $row['productUrl'] ), esc_html__( 'ver no KuantoKusta', 'pharma-hub-plugin' ) );
        if ( $product ) {
            $ids[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ), esc_html__( 'editar o produto', 'pharma-hub-plugin' ) );
        }
        ?>
        <div class="pharma-hub-head">
            <span class="grow">
                <strong class="title"><?php echo esc_html( $product ? wp_strip_all_tags( $product->get_name() ) : $row['name'] ); ?></strong>
                <?php if ( ! empty( $row['easyAdjust'] ) ) : ?>
                    <span class="pharma-hub-pill easy"><?php esc_html_e( 'Ajuste fácil', 'pharma-hub-plugin' ); ?></span>
                <?php else : ?>
                    <span class="pharma-hub-pill <?php echo esc_attr( $row['outcome'] ); ?>"><?php echo esc_html( isset( $labels[ $row['outcome'] ] ) ? $labels[ $row['outcome'] ] : $row['outcome'] ); ?></span>
                <?php endif; ?>
                <span class="sub"><?php echo implode( ' · ', $ids ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above. ?></span>
            </span>
            <div class="pharma-hub-figures">
                <div>
                    <small><?php esc_html_e( 'Preço da loja', 'pharma-hub-plugin' ); ?></small>
                    <b><?php echo esc_html( Pharma_Hub_Format::money( $row['storePriceCents'] ) ); ?></b>
                </div>
                <?php if ( null !== $row['lowestPriceCents'] ) : ?>
                    <div>
                        <small><?php esc_html_e( 'Menor preço', 'pharma-hub-plugin' ); ?></small>
                        <b><?php echo esc_html( Pharma_Hub_Format::money( $row['lowestPriceCents'] ) ); ?></b>
                        <small><?php echo esc_html( (string) $row['lowestStoreName'] ); ?></small>
                    </div>
                <?php endif; ?>
                <?php if ( null !== $difference ) : ?>
                    <div>
                        <small><?php esc_html_e( 'Diferença', 'pharma-hub-plugin' ); ?></small>
                        <b class="<?php echo esc_attr( $direction ); ?>"><?php echo esc_html( Pharma_Hub_Format::money( $difference, true ) ); ?></b>
                        <?php if ( null !== $row['differencePercent'] ) : ?>
                            <small><?php echo esc_html( Pharma_Hub_Format::percent( $row['differencePercent'] ) ); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ( null !== $row['storePosition'] ) : ?>
                    <div>
                        <small><?php esc_html_e( 'Posição', 'pharma-hub-plugin' ); ?></small>
                        <b>
                            <?php
                            /* translators: %d: position among the stores */
                            echo esc_html( sprintf( __( '%d.º', 'pharma-hub-plugin' ), $row['storePosition'] ) );
                            ?>
                        </b>
                        <small>
                            <?php
                            /* translators: %d: number of stores */
                            echo esc_html( sprintf( _n( 'de %d loja', 'de %d lojas', (int) $row['storeCount'], 'pharma-hub-plugin' ), (int) $row['storeCount'] ) );
                            ?>
                        </small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * The chart, with the period to choose and what to read in it.
     *
     * @param array[] $entries  Entries of the period, oldest first.
     * @param string  $period   Current period.
     * @param array   $filters  The report's filters, kept in every address.
     * @param string  $offer_id Hub offer id.
     * @param int     $total    Entries in the whole history.
     * @return void
     */
    private static function render_chart_panel( $entries, $period, $filters, $offer_id, $total ) {
        $periods = array(
            '30'  => __( '30 dias', 'pharma-hub-plugin' ),
            '90'  => __( '90 dias', 'pharma-hub-plugin' ),
            'all' => __( 'Tudo', 'pharma-hub-plugin' ),
        );
        $chart   = Pharma_Hub_History::chart( $entries );
        ?>
        <div class="pharma-hub-panel pharma-hub-history">
            <div class="pharma-hub-tools">
                <h2 class="grow"><?php esc_html_e( 'Preço da loja e menor preço', 'pharma-hub-plugin' ); ?></h2>
                <nav class="pharma-hub-seg" aria-label="<?php esc_attr_e( 'Período', 'pharma-hub-plugin' ); ?>">
                    <?php foreach ( $periods as $key => $label ) : ?>
                        <a href="<?php echo esc_url( self::url( $filters, $offer_id, $key ) ); ?>"<?php echo $key === $period ? ' class="current" aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>
            <?php if ( null === $chart ) : ?>
                <p class="none">
                    <?php
                    if ( 0 === $total ) {
                        esc_html_e( 'Esta oferta ainda não foi comparada em nenhuma coleta.', 'pharma-hub-plugin' );
                    } elseif ( ! $entries ) {
                        esc_html_e( 'Não houve coletas desta oferta neste período. Escolha um período maior.', 'pharma-hub-plugin' );
                    } else {
                        esc_html_e( 'O gráfico aparece a partir de duas coletas no período.', 'pharma-hub-plugin' );
                    }
                    ?>
                </p>
            <?php else : ?>
                <?php self::render_chart( $chart, $entries ); ?>
                <p class="hint"><?php esc_html_e( 'Cada degrau é uma coleta em que o preço mudou. O histórico ganha uma entrada por coleta.', 'pharma-hub-plugin' ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Draws the chart.
     *
     * The two lines differ in more than colour: one is solid and the other
     * dashed, and each carries its value at both ends.
     *
     * @param array   $chart   From Pharma_Hub_History::chart().
     * @param array[] $entries Entries drawn, for the text alternative.
     * @return void
     */
    private static function render_chart( $chart, $entries ) {
        $n          = array( 'Pharma_Hub_History', 'n' );
        $left       = Pharma_Hub_History::PLOT_LEFT;
        $right      = Pharma_Hub_History::PLOT_RIGHT;
        $bottom     = Pharma_Hub_History::PLOT_BOTTOM;
        $long       = $chart['to'] - $chart['from'] > 2 * 86400;
        $date       = function ( $at ) use ( $long ) {
            return Pharma_Hub_Format::time( gmdate( 'Y-m-d\TH:i:s\Z', $at ), $long ? 'd/m' : 'd/m H:i' );
        };
        $stores     = wp_list_pluck( $entries, 'storePriceCents' );
        $lowests    = array_filter( wp_list_pluck( $entries, 'lowestPriceCents' ), 'is_int' );
        $sides      = array_unique( wp_list_pluck( $chart['bands'], 'side' ) );
        $has_lowest = '' !== $chart['lowest_path'];
        /* translators: 1: first day, 2: last day, 3: lowest store price, 4: highest store price */
        $alt = sprintf( __( 'Gráfico entre %1$s e %2$s. O preço da loja esteve entre %3$s e %4$s.', 'pharma-hub-plugin' ), $date( $chart['from'] ), $date( $chart['to'] ), Pharma_Hub_Format::money( min( $stores ) ), Pharma_Hub_Format::money( max( $stores ) ) );
        if ( $lowests ) {
            /* translators: 1: lowest price seen, 2: highest value of the lowest price */
            $alt .= ' ' . sprintf( __( 'O menor preço no KuantoKusta esteve entre %1$s e %2$s.', 'pharma-hub-plugin' ), Pharma_Hub_Format::money( min( $lowests ) ), Pharma_Hub_Format::money( max( $lowests ) ) );
        }
        ?>
        <div class="pharma-hub-legend">
            <span><svg width="28" height="10" viewBox="0 0 28 10" aria-hidden="true"><line x1="0" y1="5" x2="28" y2="5" stroke="#173f6b" stroke-width="3"/></svg><?php esc_html_e( 'Preço da loja', 'pharma-hub-plugin' ); ?></span>
            <?php if ( $has_lowest ) : ?>
                <span><svg width="28" height="10" viewBox="0 0 28 10" aria-hidden="true"><line x1="0" y1="5" x2="28" y2="5" stroke="#c2620a" stroke-width="2" stroke-dasharray="6 4"/></svg><?php esc_html_e( 'Menor preço no KuantoKusta', 'pharma-hub-plugin' ); ?></span>
            <?php endif; ?>
            <?php if ( in_array( 'dearer', $sides, true ) ) : ?>
                <span><i style="background: #fdf0e4"></i><?php esc_html_e( 'Loja mais cara', 'pharma-hub-plugin' ); ?></span>
            <?php endif; ?>
            <?php if ( in_array( 'cheaper', $sides, true ) ) : ?>
                <span><i style="background: #e3f3ea"></i><?php esc_html_e( 'Loja mais barata', 'pharma-hub-plugin' ); ?></span>
            <?php endif; ?>
        </div>
        <div class="pharma-hub-chart">
            <svg viewBox="0 0 <?php echo (int) Pharma_Hub_History::CHART_WIDTH; ?> <?php echo (int) Pharma_Hub_History::CHART_HEIGHT; ?>" role="img" aria-label="<?php echo esc_attr( $alt ); ?>">
                <?php foreach ( $chart['y_ticks'] as $i => $tick ) : ?>
                    <line x1="<?php echo (int) $left; ?>" x2="<?php echo (int) $right; ?>" y1="<?php echo esc_attr( call_user_func( $n, $tick['y'] ) ); ?>" y2="<?php echo esc_attr( call_user_func( $n, $tick['y'] ) ); ?>" stroke="<?php echo 0 === $i ? '#8c8f94' : '#dcdcde'; ?>"/>
                    <text x="<?php echo (int) $left - 8; ?>" y="<?php echo esc_attr( call_user_func( $n, $tick['y'] + 4 ) ); ?>" text-anchor="end" font-size="12" fill="#50575e"><?php echo esc_html( Pharma_Hub_Format::money( $tick['cents'] ) ); ?></text>
                <?php endforeach; ?>
                <?php
                $last_tick = count( $chart['x_ticks'] ) - 1;
                foreach ( $chart['x_ticks'] as $i => $tick ) :
                    $anchor = 0 === $i ? 'start' : ( $i === $last_tick ? 'end' : 'middle' );
                    ?>
                    <text x="<?php echo esc_attr( call_user_func( $n, $tick['x'] ) ); ?>" y="<?php echo (int) $bottom + 22; ?>" text-anchor="<?php echo esc_attr( $anchor ); ?>" font-size="12" fill="#50575e"><?php echo esc_html( $date( $tick['at'] ) ); ?></text>
                <?php endforeach; ?>
                <?php foreach ( $chart['bands'] as $band ) : ?>
                    <rect x="<?php echo esc_attr( call_user_func( $n, $band['x'] ) ); ?>" y="<?php echo esc_attr( call_user_func( $n, $band['y'] ) ); ?>" width="<?php echo esc_attr( call_user_func( $n, $band['width'] ) ); ?>" height="<?php echo esc_attr( call_user_func( $n, $band['height'] ) ); ?>" fill="<?php echo 'dearer' === $band['side'] ? '#fdf0e4' : '#e3f3ea'; ?>"/>
                <?php endforeach; ?>
                <?php if ( $has_lowest ) : ?>
                    <path d="<?php echo esc_attr( $chart['lowest_path'] ); ?>" fill="none" stroke="#c2620a" stroke-width="2" stroke-dasharray="6 4"/>
                <?php endif; ?>
                <path d="<?php echo esc_attr( $chart['store_path'] ); ?>" fill="none" stroke="#173f6b" stroke-width="3"/>
                <?php foreach ( $chart['markers'] as $marker ) : ?>
                    <circle cx="<?php echo esc_attr( call_user_func( $n, $marker['x'] ) ); ?>" cy="<?php echo esc_attr( call_user_func( $n, $marker['y'] ) ); ?>" r="5" fill="#fff" stroke="#173f6b" stroke-width="3"><title><?php echo esc_html( self::move_text( $marker ) . ' (' . $date( $marker['at'] ) . ')' ); ?></title></circle>
                <?php endforeach; ?>
                <?php
                // Only the latest move is written out; the others keep their
                // mark, with the text on hover and in the table. When that
                // move is the last collection, its text already gives the
                // price at the right end.
                $marker  = $chart['markers'] ? $chart['markers'][ count( $chart['markers'] ) - 1 ] : null;
                $at_end  = null !== $marker && $marker['x'] >= $chart['last']['x'] - 1;
                self::render_end_labels( $chart['first'], 'start', true );
                self::render_end_labels( $chart['last'], 'end', ! $at_end );
                if ( null !== $marker ) {
                    $start  = $marker['x'] < $left + 0.6 * ( $right - $left );
                    $above  = ( $marker['moved'] < 0 ) === $start;
                    printf(
                        '<text x="%s" y="%s" text-anchor="%s" font-size="12" font-weight="600" fill="#173f6b">%s</text>',
                        esc_attr( call_user_func( $n, $marker['x'] + ( $start ? 10 : -10 ) ) ),
                        esc_attr( call_user_func( $n, $marker['y'] + ( $above ? -10 : 20 ) ) ),
                        $start ? 'start' : 'end',
                        esc_html( self::move_text( $marker ) )
                    );
                }
                ?>
            </svg>
        </div>
        <?php
    }

    /**
     * What the store did at a mark of the chart, in words.
     *
     * @param array $marker A marker from Pharma_Hub_History::chart().
     * @return string
     */
    private static function move_text( $marker ) {
        return sprintf(
            $marker['moved'] < 0
                /* translators: %s: amount of money */
                ? __( 'A loja baixou para %s', 'pharma-hub-plugin' )
                /* translators: %s: amount of money */
                : __( 'A loja subiu para %s', 'pharma-hub-plugin' ),
            Pharma_Hub_Format::money( $marker['cents'] )
        );
    }

    /**
     * Writes the two prices at one end of the chart, each on the outer side
     * of its line so they never sit on top of each other.
     *
     * @param array  $end        First or last point, from Pharma_Hub_History::chart().
     * @param string $side       "start" for the left end, "end" for the right one.
     * @param bool   $with_store Write the store's price too.
     * @return void
     */
    private static function render_end_labels( $end, $side, $with_store ) {
        $x           = $end['x'] + ( 'start' === $side ? 6 : -6 );
        $store_above = null === $end['lowest_y'] || $end['store_y'] <= $end['lowest_y'];
        $labels      = array();
        if ( $with_store ) {
            $labels[] = array( $end['store_y'], $end['store_cents'], $store_above, '#173f6b' );
        }
        if ( null !== $end['lowest_y'] ) {
            $labels[] = array( $end['lowest_y'], $end['lowest_cents'], ! $store_above, '#8a3b00' );
        }
        foreach ( $labels as $label ) {
            printf(
                '<text x="%s" y="%s" text-anchor="%s" font-size="12" font-weight="600" fill="%s">%s</text>',
                esc_attr( Pharma_Hub_History::n( $x ) ),
                esc_attr( Pharma_Hub_History::n( $label[0] + ( $label[2] ? -8 : 17 ) ) ),
                esc_attr( $side ),
                esc_attr( $label[3] ),
                esc_html( Pharma_Hub_Format::money( $label[1] ) )
            );
        }
    }

    /**
     * A few numbers about the period.
     *
     * @param array $summary From Pharma_Hub_History::summary().
     * @return void
     */
    private static function render_summary( $summary ) {
        $difference = function ( $cents ) {
            if ( null === $cents ) {
                return array( '', '—' );
            }
            return array( 0 === $cents ? '' : ( $cents > 0 ? ' is-up' : ' is-down' ), Pharma_Hub_Format::money( $cents, true ) );
        };
        $min        = $difference( $summary['min_difference'] );
        $max        = $difference( $summary['max_difference'] );
        if ( null === $summary['best_position'] ) {
            $position = '—';
        } elseif ( $summary['best_position'] === $summary['worst_position'] ) {
            /* translators: %d: position among the stores */
            $position = sprintf( __( '%d.º', 'pharma-hub-plugin' ), $summary['best_position'] );
        } else {
            /* translators: 1: best position, 2: worst position */
            $position = sprintf( __( '%1$d.º a %2$d.º', 'pharma-hub-plugin' ), $summary['best_position'], $summary['worst_position'] );
        }
        ?>
        <div class="pharma-hub-cards">
            <div class="pharma-hub-card<?php echo esc_attr( $min[0] ); ?>"><b><?php echo esc_html( $min[1] ); ?></b><span><?php esc_html_e( 'Menor diferença no período', 'pharma-hub-plugin' ); ?></span></div>
            <div class="pharma-hub-card<?php echo esc_attr( $max[0] ); ?>"><b><?php echo esc_html( $max[1] ); ?></b><span><?php esc_html_e( 'Maior diferença no período', 'pharma-hub-plugin' ); ?></span></div>
            <div class="pharma-hub-card">
                <b>
                    <?php
                    /* translators: 1: collections in which the store was the cheapest, 2: collections */
                    echo esc_html( sprintf( __( '%1$d de %2$d', 'pharma-hub-plugin' ), $summary['cheapest'], $summary['count'] ) );
                    ?>
                </b>
                <span><?php esc_html_e( 'Coletas em que a loja foi a mais barata', 'pharma-hub-plugin' ); ?></span>
            </div>
            <div class="pharma-hub-card"><b><?php echo esc_html( $position ); ?></b><span><?php esc_html_e( 'Posição entre as lojas', 'pharma-hub-plugin' ); ?></span></div>
        </div>
        <?php
    }

    /**
     * The moments when something changed, newest first.
     *
     * @param array[] $changes From Pharma_Hub_History::changes().
     * @param int     $count   Collections in the period.
     * @return void
     */
    private static function render_changes( $changes, $count ) {
        $day = function ( $at ) {
            return Pharma_Hub_Format::time( gmdate( 'Y-m-d\TH:i:s\Z', $at ), 'd/m/Y' );
        };
        ?>
        <div class="pharma-hub-tools">
            <h2><?php esc_html_e( 'O que mudou', 'pharma-hub-plugin' ); ?></h2>
            <span class="count">
                <?php
                /* translators: %d: number of collections */
                echo esc_html( sprintf( _n( '%d coleta no período.', '%d coletas no período.', $count, 'pharma-hub-plugin' ), $count ) );
                echo ' ';
                esc_html_e( 'As coletas seguidas em que nada mudou ficam numa só linha.', 'pharma-hub-plugin' );
                ?>
            </span>
        </div>
        <div class="pharma-hub-scroll">
            <table class="pharma-hub-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Desde', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col" class="num"><?php esc_html_e( 'Preço da loja', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col" class="num"><?php esc_html_e( 'Menor preço', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col" class="num"><?php esc_html_e( 'Diferença', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col" class="num"><?php esc_html_e( 'Posição', 'pharma-hub-plugin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $changes as $change ) :
                        $entry      = $change['entry'];
                        $previous   = $change['previous'];
                        $difference = $entry['differenceCents'];
                        $direction  = null === $difference || 0 === $difference ? '' : ( $difference > 0 ? 'is-up' : 'is-down' );
                        ?>
                        <tr<?php echo 0 !== $change['store_moved'] ? ' class="is-easy"' : ''; ?>>
                            <td>
                                <span class="main"><?php echo esc_html( $day( $change['since'] ) ); ?></span>
                                <span class="sub">
                                    <?php
                                    if ( $change['count'] > 1 ) {
                                        /* translators: 1: date, 2: number of collections */
                                        echo esc_html( sprintf( __( 'até %1$s · %2$d coletas', 'pharma-hub-plugin' ), $day( $change['until'] ), $change['count'] ) );
                                    } else {
                                        esc_html_e( '1 coleta', 'pharma-hub-plugin' );
                                    }
                                    ?>
                                </span>
                            </td>
                            <td class="num">
                                <span class="main"><?php echo esc_html( Pharma_Hub_Format::money( $entry['storePriceCents'] ) ); ?></span>
                                <?php if ( 0 !== $change['store_moved'] ) : ?>
                                    <span class="sub">
                                        <?php
                                        echo esc_html(
                                            sprintf(
                                                $change['store_moved'] < 0
                                                    /* translators: %s: the price before */
                                                    ? __( 'a loja baixou · era %s', 'pharma-hub-plugin' )
                                                    /* translators: %s: the price before */
                                                    : __( 'a loja subiu · era %s', 'pharma-hub-plugin' ),
                                                Pharma_Hub_Format::money( $previous['storePriceCents'] )
                                            )
                                        );
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ( null !== $entry['lowestPriceCents'] ) : ?>
                                    <span class="main"><?php echo esc_html( Pharma_Hub_Format::money( $entry['lowestPriceCents'] ) ); ?></span>
                                    <span class="sub">
                                        <?php
                                        echo esc_html( (string) $entry['lowestStoreName'] );
                                        if ( $change['lowest_moved'] && null !== $previous['lowestPriceCents'] ) {
                                            /* translators: %s: the price before */
                                            echo esc_html( ' · ' . sprintf( __( 'era %s', 'pharma-hub-plugin' ), Pharma_Hub_Format::money( $previous['lowestPriceCents'] ) ) );
                                        }
                                        ?>
                                    </span>
                                <?php elseif ( 'only_store' === $entry['outcome'] ) : ?>
                                    <span class="none"><?php esc_html_e( 'sem outras lojas', 'pharma-hub-plugin' ); ?></span>
                                <?php else : ?>
                                    <span class="none">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ( null !== $difference ) : ?>
                                    <span class="diff <?php echo esc_attr( $direction ); ?>"><?php echo esc_html( Pharma_Hub_Format::money( $difference, true ) ); ?></span>
                                    <?php if ( isset( $entry['differencePercent'] ) && is_numeric( $entry['differencePercent'] ) ) : ?>
                                        <span class="sub"><?php echo esc_html( Pharma_Hub_Format::percent( $entry['differencePercent'] ) ); ?></span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="none">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php
                                if ( null !== $entry['storePosition'] && null !== $entry['storeCount'] ) {
                                    /* translators: 1: position, 2: number of stores */
                                    echo esc_html( sprintf( __( '%1$d.º de %2$d', 'pharma-hub-plugin' ), $entry['storePosition'], $entry['storeCount'] ) );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * The address of the history of an offer, keeping the report's filters
     * so that going back returns to the same list.
     *
     * @param array  $filters  The report's filters.
     * @param string $offer_id Hub offer id.
     * @param string $period   A key of Pharma_Hub_History::PERIODS.
     * @return string
     */
    public static function url( $filters, $offer_id, $period = Pharma_Hub_History::DEFAULT_PERIOD ) {
        $extra = array( 'history' => $offer_id );
        if ( Pharma_Hub_History::DEFAULT_PERIOD !== $period ) {
            $extra['period'] = $period;
        }
        return Pharma_Hub_Admin_Report::url( $filters, $extra );
    }
}
