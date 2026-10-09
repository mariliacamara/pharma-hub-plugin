<?php
/**
 * The "Relatório" tab: the store's prices against the other stores on
 * KuantoKusta, and its CSV export.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the report tab and exports it.
 */
class Pharma_Hub_Admin_Report {

    const ACTION_EXPORT = 'pharma_hub_export_csv';

    /**
     * Hooks the export.
     *
     * @return void
     */
    public static function register() {
        add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
    }

    /**
     * What each outcome is called on screen.
     *
     * @return array
     */
    private static function outcome_labels() {
        return array(
            'cheapest'       => __( 'Mais barata', 'pharma-hub-plugin' ),
            'tied'           => __( 'Empatada', 'pharma-hub-plugin' ),
            'more_expensive' => __( 'Mais cara', 'pharma-hub-plugin' ),
            'only_store'     => __( 'Única loja', 'pharma-hub-plugin' ),
            'no_data'        => __( 'Sem dados', 'pharma-hub-plugin' ),
        );
    }

    /**
     * Renders the tab.
     *
     * @return void
     */
    public static function render() {
        $client = Pharma_Hub_Settings::client();
        if ( null === $client ) {
            printf(
                '<p>%s <a href="%s">%s</a></p>',
                esc_html__( 'Ainda não está ligado ao hub.', 'pharma-hub-plugin' ),
                esc_url( Pharma_Hub_Admin::tab_url( 'settings' ) ),
                esc_html__( 'Configurar', 'pharma-hub-plugin' )
            );
            return;
        }

        $filters = Pharma_Hub_Report::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only known values are kept by filters_from().
        try {
            $report = Pharma_Hub_Report::load( $client, $filters['state'], ! empty( $_GET['refresh'] ) );
        } catch ( Pharma_Hub_Error $error ) {
            printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( Pharma_Hub_Admin::error_message( $error ) ) );
            return;
        }

        list( $rows, $links, $products ) = self::prepare( $report, $filters );

        self::render_styles();
        self::render_header( $report, $filters );
        Pharma_Hub_Admin_Runs::render_panel( $client );
        self::render_filters( $filters, count( $rows ) );
        self::render_table( $rows, $links, $products, $filters );
    }

    /**
     * Streams the report, as filtered on screen, as a CSV file for Excel.
     *
     * @return void
     */
    public static function handle_export() {
        Pharma_Hub_Admin::check_capability();
        check_admin_referer( self::ACTION_EXPORT );

        $client = Pharma_Hub_Settings::client();
        if ( null === $client ) {
            Pharma_Hub_Admin::redirect_back( array( array( 'error', __( 'Configure primeiro o endereço do hub e o token.', 'pharma-hub-plugin' ) ) ) );
        }
        $filters = Pharma_Hub_Report::filters_from( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only known values are kept by filters_from().
        try {
            $report = Pharma_Hub_Report::load( $client, $filters['state'] );
        } catch ( Pharma_Hub_Error $error ) {
            Pharma_Hub_Admin::redirect_back( array( array( 'error', Pharma_Hub_Admin::error_message( $error ) ) ), 'report' );
        }

        list( $rows, , $products ) = self::prepare( $report, $filters );
        $labels                    = self::outcome_labels();
        $yes                       = __( 'Sim', 'pharma-hub-plugin' );
        $no                        = __( 'Não', 'pharma-hub-plugin' );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="relatorio-kuantokusta-' . Pharma_Hub_Format::time( gmdate( 'c' ), 'Y-m-d-Hi' ) . '.csv"' );

        $out = fopen( 'php://output', 'w' );
        // The byte order mark makes Excel read the file as UTF-8.
        fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Streaming to the response.
        $head = array(
            __( 'Oferta no KuantoKusta', 'pharma-hub-plugin' ),
            __( 'REF', 'pharma-hub-plugin' ),
            __( 'EAN', 'pharma-hub-plugin' ),
            __( 'Produto na loja', 'pharma-hub-plugin' ),
            __( 'Preço da loja (€)', 'pharma-hub-plugin' ),
            __( 'Menor preço (€)', 'pharma-hub-plugin' ),
            __( 'Loja mais barata', 'pharma-hub-plugin' ),
            __( 'Diferença (€)', 'pharma-hub-plugin' ),
            __( 'Diferença (%)', 'pharma-hub-plugin' ),
            __( 'Posição', 'pharma-hub-plugin' ),
            __( 'N.º de lojas', 'pharma-hub-plugin' ),
            __( 'Situação', 'pharma-hub-plugin' ),
            __( 'Ajuste fácil', 'pharma-hub-plugin' ),
            __( 'Diferença suspeita', 'pharma-hub-plugin' ),
            __( 'Preço com portes (€)', 'pharma-hub-plugin' ),
            __( 'Menor total com portes (€)', 'pharma-hub-plugin' ),
            __( 'Loja do menor total', 'pharma-hub-plugin' ),
            __( 'Diferença com portes (€)', 'pharma-hub-plugin' ),
            __( 'Comparado em', 'pharma-hub-plugin' ),
            __( 'Página no KuantoKusta', 'pharma-hub-plugin' ),
        );
        fwrite( $out, Pharma_Hub_Format::csv_line( array_map( array( 'Pharma_Hub_Format', 'csv_cell' ), $head ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

        foreach ( $rows as $row ) {
            $id      = (string) $row['offerId'];
            $product = isset( $products[ $id ] ) ? $products[ $id ] : null;
            $number  = function ( $cents ) {
                return Pharma_Hub_Format::csv_cell( Pharma_Hub_Format::amount( $cents, false, false ), true );
            };
            $cells   = array(
                Pharma_Hub_Format::csv_cell( $row['name'] ),
                Pharma_Hub_Format::csv_cell( $row['sku'] ),
                Pharma_Hub_Format::csv_cell( $row['ean'] ),
                Pharma_Hub_Format::csv_cell( $product ? wp_strip_all_tags( $product->get_formatted_name() ) : '' ),
                $number( $row['storePriceCents'] ),
                $number( $row['lowestPriceCents'] ),
                Pharma_Hub_Format::csv_cell( $row['lowestStoreName'] ),
                $number( $row['differenceCents'] ),
                Pharma_Hub_Format::csv_cell( null === $row['differencePercent'] ? '' : str_replace( '.', ',', sprintf( '%.1f', $row['differencePercent'] ) ), true ),
                Pharma_Hub_Format::csv_cell( $row['storePosition'], true ),
                Pharma_Hub_Format::csv_cell( $row['storeCount'], true ),
                Pharma_Hub_Format::csv_cell( isset( $labels[ $row['outcome'] ] ) ? $labels[ $row['outcome'] ] : $row['outcome'] ),
                empty( $row['easyAdjust'] ) ? $no : $yes,
                empty( $row['checkLink'] ) ? $no : $yes,
                $number( $row['storeTotalCents'] ),
                $number( $row['lowestTotalCents'] ),
                Pharma_Hub_Format::csv_cell( $row['lowestTotalStoreName'] ),
                $number( $row['totalDifferenceCents'] ),
                Pharma_Hub_Format::csv_cell( Pharma_Hub_Format::time( $row['comparedAt'] ) ),
                Pharma_Hub_Format::csv_cell( $row['productUrl'] ),
            );
            fwrite( $out, Pharma_Hub_Format::csv_line( $cells ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        }
        fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        exit;
    }

    /**
     * Links the report's offers, then filters and sorts the rows.
     *
     * @param array $report  Report from the hub.
     * @param array $filters From Pharma_Hub_Report::filters_from().
     * @return array array( rows, links by offer id, linked WC_Product by offer id ).
     */
    private static function prepare( $report, $filters ) {
        $rows   = $report['rows'];
        $offers = array_map(
            function ( $row ) {
                return array(
                    'id'       => (string) $row['offerId'],
                    'sku'      => $row['sku'],
                    'ean'      => $row['ean'],
                    'storeUrl' => $row['storeUrl'],
                );
            },
            $rows
        );
        $links    = Pharma_Hub_Links::resolve( $offers );
        $products = array();
        $names    = array();
        foreach ( $links as $offer_id => $link ) {
            if ( 'linked' === $link['state'] ) {
                $product = wc_get_product( $link['product_id'] );
                if ( $product ) {
                    $products[ $offer_id ] = $product;
                    $names[ $offer_id ]    = wp_strip_all_tags( $product->get_formatted_name() );
                }
            }
        }

        $rows = Pharma_Hub_Report::filter( $rows, $filters, $links );
        $rows = Pharma_Hub_Report::sort( $rows, $filters['orderby'], $filters['order'], $names );
        return array( $rows, $links, $products );
    }

    /**
     * The colours of the highlights.
     *
     * @return void
     */
    private static function render_styles() {
        ?>
        <style>
            .pharma-hub-report td, .pharma-hub-report th { vertical-align: top; }
            .pharma-hub-report .num { text-align: right; white-space: nowrap; }
            .pharma-hub-report tr.is-easy td { background: #edfaef; }
            .pharma-hub-report tr.is-check td { background: #fcf0e3; }
            .pharma-hub-badge { display: inline-block; padding: 1px 6px; margin: 2px 4px 0 0; border-radius: 3px; font-size: 11px; line-height: 1.6; white-space: nowrap; }
            .pharma-hub-badge.easy { background: #00a32a; color: #fff; }
            .pharma-hub-badge.check { background: #dba617; color: #1d2327; }
            .pharma-hub-badge.stale, .pharma-hub-badge.unlinked { background: #dcdcde; color: #1d2327; }
            .pharma-hub-summary a { margin-right: 12px; text-decoration: none; }
            .pharma-hub-summary a.current { font-weight: 600; color: #1d2327; border-bottom: 2px solid #2271b1; }
        </style>
        <?php
    }

    /**
     * When the data was collected, how that went, and the counts.
     *
     * @param array $report  Report from the hub.
     * @param array $filters Current filters.
     * @return void
     */
    private static function render_header( $report, $filters ) {
        $run     = isset( $report['run'] ) ? $report['run'] : null;
        $summary = isset( $report['summary'] ) ? $report['summary'] : array();
        ?>
        <p>
            <?php if ( ! $run ) : ?>
                <?php esc_html_e( 'Ainda não houve nenhuma coleta de preços.', 'pharma-hub-plugin' ); ?>
            <?php else : ?>
                <?php
                printf(
                    /* translators: 1: date and time, 2: how it ended */
                    esc_html__( 'Última coleta: %1$s (hora de Portugal), %2$s.', 'pharma-hub-plugin' ),
                    '<strong>' . esc_html( Pharma_Hub_Format::time( $run['finishedAt'] ) ) . '</strong>',
                    esc_html( self::run_status( $run ) )
                );
                ?>
            <?php endif; ?>
            <a href="<?php echo esc_url( self::url( $filters, array( 'refresh' => '1' ) ) ); ?>"><?php esc_html_e( 'Atualizar', 'pharma-hub-plugin' ); ?></a>
        </p>
        <?php if ( ! empty( $summary['stale'] ) ) : ?>
            <div class="notice notice-warning inline"><p>
                <?php
                printf(
                    /* translators: %d: number of products */
                    esc_html( _n( '%d produto mostra o preço de uma coleta anterior, porque a última não chegou a ele.', '%d produtos mostram preços de uma coleta anterior, porque a última não chegou a eles.', (int) $summary['stale'], 'pharma-hub-plugin' ) ),
                    (int) $summary['stale']
                );
                ?>
            </p></div>
        <?php endif; ?>
        <p class="pharma-hub-summary">
            <?php
            echo esc_html__( 'Mostrar:', 'pharma-hub-plugin' ) . ' ';
            // Each link shows only its own rows: it replaces the outcome and
            // flag filters instead of adding to them, which would leave the
            // table empty (cheapest and easy adjust never meet). The offer
            // state, the shipping switch and the order are kept.
            $base  = array_merge(
                $filters,
                array(
                    'outcome'  => '',
                    'easy'     => false,
                    'check'    => false,
                    'unlinked' => false,
                )
            );
            $items = array(
                array( __( 'Todas', 'pharma-hub-plugin' ), isset( $summary['offers'] ) ? (int) $summary['offers'] : 0, array(), '' ),
            );
            foreach ( self::outcome_labels() as $outcome => $label ) {
                $items[] = array( $label, isset( $summary[ $outcome ] ) ? (int) $summary[ $outcome ] : 0, array( 'outcome' => $outcome ), '' );
            }
            $items[] = array( __( 'Ajuste fácil', 'pharma-hub-plugin' ), isset( $summary['easyAdjust'] ) ? (int) $summary['easyAdjust'] : 0, array( 'easy' => true ), 'easy' );
            $items[] = array( __( 'Diferença suspeita', 'pharma-hub-plugin' ), isset( $summary['checkLink'] ) ? (int) $summary['checkLink'] : 0, array( 'check' => true ), 'check' );

            $links = array();
            foreach ( $items as $item ) {
                list( $label, $count, $override, $badge ) = $item;
                $target  = array_merge( $base, $override );
                $current = self::query_args( $target ) === self::query_args( $filters );
                $text    = sprintf( '%s: %d', $label, $count );
                $links[] = sprintf(
                    '<a href="%s"%s>%s</a>',
                    esc_url( self::url( $target ) ),
                    $current ? ' class="current" aria-current="page"' : '',
                    '' === $badge
                        ? esc_html( $text )
                        : sprintf( '<span class="pharma-hub-badge %s">%s</span>', esc_attr( $badge ), esc_html( $text ) )
                );
            }
            echo implode( ' ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
            ?>
        </p>
        <p class="description">
            <?php
            printf(
                /* translators: 1: easy-adjust threshold, 2: link-check percentage */
                esc_html__( 'Ajuste fácil: a loja está mais cara por até %1$s. Diferença suspeita: a diferença é de %2$s%% ou mais. Costuma querer dizer que a página do KuantoKusta é de outro produto, variante ou embalagem; confirme antes de mudar o preço.', 'pharma-hub-plugin' ),
                esc_html( Pharma_Hub_Format::money( isset( $report['easyAdjustCents'] ) ? (int) $report['easyAdjustCents'] : 10 ) ),
                (int) ( isset( $report['checkLinkPercent'] ) ? $report['checkLinkPercent'] : 50 )
            );
            ?>
        </p>
        <?php
    }

    /**
     * How the last collection ended, in words.
     *
     * @param array $run Collection from the hub.
     * @return string
     */
    private static function run_status( $run ) {
        switch ( $run['status'] ) {
            case 'succeeded':
                return __( 'todas as páginas lidas', 'pharma-hub-plugin' );
            case 'partial':
                return __( 'algumas páginas não puderam ser lidas', 'pharma-hub-plugin' );
            case 'blocked':
                return __( 'interrompida porque o KuantoKusta recusou o acesso', 'pharma-hub-plugin' );
            default:
                /* translators: %s: error code */
                return sprintf( __( 'falhou (%s)', 'pharma-hub-plugin' ), (string) $run['errorCode'] );
        }
    }

    /**
     * The filter form and the export button.
     *
     * @param array $filters Current filters.
     * @param int   $shown   Rows shown with these filters.
     * @return void
     */
    private static function render_filters( $filters, $shown ) {
        ?>
        <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="margin: 12px 0">
            <input type="hidden" name="page" value="<?php echo esc_attr( Pharma_Hub_Admin::PAGE ); ?>">
            <input type="hidden" name="tab" value="report">
            <input type="hidden" name="orderby" value="<?php echo esc_attr( $filters['orderby'] ); ?>">
            <input type="hidden" name="order" value="<?php echo esc_attr( $filters['order'] ); ?>">
            <select name="outcome">
                <option value=""><?php esc_html_e( 'Todas as situações', 'pharma-hub-plugin' ); ?></option>
                <?php foreach ( self::outcome_labels() as $outcome => $label ) : ?>
                    <option value="<?php echo esc_attr( $outcome ); ?>" <?php selected( $filters['outcome'], $outcome ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="state">
                <option value="active" <?php selected( $filters['state'], 'active' ); ?>><?php esc_html_e( 'Com stock no KuantoKusta', 'pharma-hub-plugin' ); ?></option>
                <option value="all" <?php selected( $filters['state'], 'all' ); ?>><?php esc_html_e( 'Incluir sem stock e retiradas', 'pharma-hub-plugin' ); ?></option>
            </select>
            <label><input type="checkbox" name="easy" value="1" <?php checked( $filters['easy'] ); ?>> <?php esc_html_e( 'Só ajuste fácil', 'pharma-hub-plugin' ); ?></label>
            <label><input type="checkbox" name="check" value="1" <?php checked( $filters['check'] ); ?>> <?php esc_html_e( 'Só diferença suspeita', 'pharma-hub-plugin' ); ?></label>
            <label><input type="checkbox" name="unlinked" value="1" <?php checked( $filters['unlinked'] ); ?>> <?php esc_html_e( 'Só sem vínculo', 'pharma-hub-plugin' ); ?></label>
            <label><input type="checkbox" name="shipping" value="1" <?php checked( $filters['shipping'] ); ?>> <?php esc_html_e( 'Comparar com portes', 'pharma-hub-plugin' ); ?></label>
            <button type="submit" class="button"><?php esc_html_e( 'Filtrar', 'pharma-hub-plugin' ); ?></button>
            <?php
            /* translators: %d: number of products shown */
            printf( ' <span>%s</span>', esc_html( sprintf( _n( '%d produto', '%d produtos', $shown, 'pharma-hub-plugin' ), $shown ) ) );
            ?>
        </form>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 12px">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>">
            <?php wp_nonce_field( self::ACTION_EXPORT ); ?>
            <?php foreach ( self::query_args( $filters ) as $key => $value ) : ?>
                <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
            <?php endforeach; ?>
            <button type="submit" class="button"><?php esc_html_e( 'Transferir CSV', 'pharma-hub-plugin' ); ?></button>
        </form>
        <?php
    }

    /**
     * The table.
     *
     * @param array[] $rows     Rows to show.
     * @param array   $links    Resolved links by offer id.
     * @param array   $products Linked products by offer id.
     * @param array   $filters  Current filters.
     * @return void
     */
    private static function render_table( $rows, $links, $products, $filters ) {
        $shipping = $filters['shipping'];
        $store    = get_transient( Pharma_Hub_Settings::TRANSIENT_STORE );
        $price    = is_array( $store ) && ! empty( $store['name'] )
            /* translators: %s: store name */
            ? sprintf( __( 'Preço %s', 'pharma-hub-plugin' ), $store['name'] )
            : __( 'Preço da loja', 'pharma-hub-plugin' );
        $labels   = self::outcome_labels();
        ?>
        <table class="widefat striped pharma-hub-report">
            <thead>
                <tr>
                    <th scope="col"><?php self::sort_link( __( 'Produto', 'pharma-hub-plugin' ), 'name', $filters ); ?></th>
                    <th scope="col" class="num"><?php self::sort_link( $shipping ? $price . ' ' . __( 'com portes', 'pharma-hub-plugin' ) : $price, 'store_price', $filters ); ?></th>
                    <th scope="col" class="num"><?php echo esc_html( $shipping ? __( 'Menor total com portes', 'pharma-hub-plugin' ) : __( 'Menor preço', 'pharma-hub-plugin' ) ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Loja mais barata', 'pharma-hub-plugin' ); ?></th>
                    <th scope="col" class="num"><?php self::sort_link( __( 'Diferença', 'pharma-hub-plugin' ), 'difference', $filters ); ?></th>
                    <?php if ( ! $shipping ) : ?>
                        <th scope="col" class="num"><?php self::sort_link( __( 'Dif. %', 'pharma-hub-plugin' ), 'percent', $filters ); ?></th>
                        <th scope="col" class="num"><?php esc_html_e( 'Posição', 'pharma-hub-plugin' ); ?></th>
                    <?php endif; ?>
                    <th scope="col"><?php esc_html_e( 'Situação', 'pharma-hub-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="8"><?php esc_html_e( 'Nenhum produto com estes filtros.', 'pharma-hub-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php
                foreach ( $rows as $row ) :
                    $id      = (string) $row['offerId'];
                    $product = isset( $products[ $id ] ) ? $products[ $id ] : null;
                    $class   = ! empty( $row['checkLink'] ) ? 'is-check' : ( ! empty( $row['easyAdjust'] ) ? 'is-easy' : '' );
                    ?>
                    <tr class="<?php echo esc_attr( $class ); ?>">
                        <td>
                            <?php if ( $product ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ); ?>"><strong><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></strong></a>
                            <?php else : ?>
                                <strong><?php echo esc_html( $row['name'] ); ?></strong>
                                <span class="pharma-hub-badge unlinked"><?php esc_html_e( 'sem vínculo', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                            <br><small>
                                <?php echo esc_html( $row['sku'] ? sprintf( /* translators: %s: SKU */ __( 'REF %s', 'pharma-hub-plugin' ), $row['sku'] ) : '' ); ?>
                                <a href="<?php echo esc_url( $row['productUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ver no KuantoKusta', 'pharma-hub-plugin' ); ?></a>
                            </small>
                        </td>
                        <?php if ( $shipping ) : ?>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::money( $row['storeTotalCents'] ) ); ?></td>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::money( $row['lowestTotalCents'] ) ); ?></td>
                            <td><?php echo esc_html( (string) $row['lowestTotalStoreName'] ); ?></td>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::money( $row['totalDifferenceCents'], true ) ); ?></td>
                        <?php else : ?>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::money( $row['storePriceCents'] ) ); ?></td>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::money( $row['lowestPriceCents'] ) ); ?></td>
                            <td><?php echo esc_html( (string) $row['lowestStoreName'] ); ?></td>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::money( $row['differenceCents'], true ) ); ?></td>
                            <td class="num"><?php echo esc_html( Pharma_Hub_Format::percent( $row['differencePercent'] ) ); ?></td>
                            <td class="num">
                                <?php
                                if ( null !== $row['storePosition'] ) {
                                    /* translators: 1: position, 2: number of stores */
                                    echo esc_html( sprintf( __( '%1$d.º de %2$d', 'pharma-hub-plugin' ), $row['storePosition'], $row['storeCount'] ) );
                                }
                                ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <?php echo esc_html( isset( $labels[ $row['outcome'] ] ) ? $labels[ $row['outcome'] ] : $row['outcome'] ); ?>
                            <?php if ( ! empty( $row['easyAdjust'] ) ) : ?>
                                <br><span class="pharma-hub-badge easy"><?php esc_html_e( 'Ajuste fácil', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $row['checkLink'] ) ) : ?>
                                <br><span class="pharma-hub-badge check"><?php esc_html_e( 'Diferença suspeita', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $row['stale'] ) ) : ?>
                                <br><span class="pharma-hub-badge stale" title="<?php echo esc_attr( Pharma_Hub_Format::time( $row['comparedAt'] ) ); ?>"><?php esc_html_e( 'coleta anterior', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * A column title that sorts the table, toggling the direction.
     *
     * @param string $label   Column title.
     * @param string $orderby Sort key.
     * @param array  $filters Current filters.
     * @return void
     */
    private static function sort_link( $label, $orderby, $filters ) {
        $active = $filters['orderby'] === $orderby;
        $order  = $active && 'asc' === $filters['order'] ? 'desc' : 'asc';
        $arrow  = $active ? ( 'asc' === $filters['order'] ? ' ▲' : ' ▼' ) : '';
        printf(
            '<a href="%s">%s%s</a>',
            esc_url(
                self::url(
                    array_merge(
                        $filters,
                        array(
                            'orderby' => $orderby,
                            'order'   => $order,
                        )
                    )
                )
            ),
            esc_html( $label ),
            esc_html( $arrow )
        );
    }

    /**
     * The filters as query arguments, leaving out the defaults.
     *
     * @param array $filters Filters.
     * @return array
     */
    private static function query_args( $filters ) {
        $args = array();
        if ( 'active' !== $filters['state'] ) {
            $args['state'] = $filters['state'];
        }
        if ( '' !== $filters['outcome'] ) {
            $args['outcome'] = $filters['outcome'];
        }
        foreach ( array( 'easy', 'check', 'unlinked', 'shipping' ) as $flag ) {
            if ( $filters[ $flag ] ) {
                $args[ $flag ] = '1';
            }
        }
        if ( 'name' !== $filters['orderby'] || 'asc' !== $filters['order'] ) {
            $args['orderby'] = $filters['orderby'];
            $args['order']   = $filters['order'];
        }
        return $args;
    }

    /**
     * The address of the report with these filters.
     *
     * @param array $filters Filters.
     * @param array $extra   More arguments.
     * @return string
     */
    private static function url( $filters, $extra = array() ) {
        return Pharma_Hub_Admin::tab_url( 'report', array_merge( self::query_args( $filters ), $extra ) );
    }
}
