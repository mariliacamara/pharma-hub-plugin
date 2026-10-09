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
                esc_url( Pharma_Hub_Admin::connection_url() ),
                esc_html__( 'Configurar a ligação', 'pharma-hub-plugin' )
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

        self::render_header( $report, $filters );
        Pharma_Hub_Admin_Runs::render_panel( $client );
        self::render_summary( $report, $filters );
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
            Pharma_Hub_Admin::redirect_back( array( array( 'error', __( 'Configure primeiro o endereço do hub e o token.', 'pharma-hub-plugin' ) ) ), 'connection' );
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
     * When the data was collected and how that went.
     *
     * @param array $report  Report from the hub.
     * @param array $filters Current filters.
     * @return void
     */
    private static function render_header( $report, $filters ) {
        $run     = isset( $report['run'] ) ? $report['run'] : null;
        $summary = isset( $report['summary'] ) ? $report['summary'] : array();
        ?>
        <div class="pharma-hub-head">
            <div class="grow">
                <strong><?php esc_html_e( 'Preços comparados com o KuantoKusta', 'pharma-hub-plugin' ); ?></strong><br>
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
            </div>
            <a class="button" href="<?php echo esc_url( self::url( $filters, array( 'refresh' => '1' ) ) ); ?>"><?php esc_html_e( 'Atualizar', 'pharma-hub-plugin' ); ?></a>
        </div>
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
        <?php
    }

    /**
     * The hub's counts, as cards that filter the table.
     *
     * @param array $report  Report from the hub.
     * @param array $filters Current filters.
     * @return void
     */
    private static function render_summary( $report, $filters ) {
        $summary   = isset( $report['summary'] ) ? $report['summary'] : array();
        $threshold = Pharma_Hub_Format::money( isset( $report['easyAdjustCents'] ) ? (int) $report['easyAdjustCents'] : 10 );
        $labels    = self::outcome_labels();
        $count     = function ( $key ) use ( $summary ) {
            return isset( $summary[ $key ] ) ? (int) $summary[ $key ] : 0;
        };
        // Each card shows only its own rows: it replaces the outcome and
        // flag filters instead of adding to them, which would leave the
        // table empty (cheapest and easy adjust never meet). The offer
        // state, the shipping switch and the order are kept.
        $base = array_merge(
            $filters,
            array(
                'outcome'  => '',
                'easy'     => false,
                'check'    => false,
                'unlinked' => false,
            )
        );
        // Label, count, filter, class. What can be acted on comes first.
        $cards = array(
            array( __( 'Todas as ofertas', 'pharma-hub-plugin' ), $count( 'offers' ), array(), '' ),
            /* translators: %s: amount of money */
            array( sprintf( __( 'Ajuste fácil (até %s)', 'pharma-hub-plugin' ), $threshold ), $count( 'easyAdjust' ), array( 'easy' => true ), '' ),
            array( $labels['more_expensive'], $count( 'more_expensive' ), array( 'outcome' => 'more_expensive' ), 'is-up' ),
            array( $labels['tied'], $count( 'tied' ), array( 'outcome' => 'tied' ), '' ),
            array( $labels['cheapest'], $count( 'cheapest' ), array( 'outcome' => 'cheapest' ), 'is-down' ),
            array( $labels['only_store'], $count( 'only_store' ), array( 'outcome' => 'only_store' ), '' ),
            array( $labels['no_data'], $count( 'no_data' ), array( 'outcome' => 'no_data' ), '' ),
            array( __( 'Diferença suspeita', 'pharma-hub-plugin' ), $count( 'checkLink' ), array( 'check' => true ), '' ),
        );
        ?>
        <nav class="pharma-hub-cards" aria-label="<?php esc_attr_e( 'Resumo e filtros', 'pharma-hub-plugin' ); ?>">
            <?php
            foreach ( $cards as $card ) {
                list( $label, $number, $override, $class ) = $card;
                $target  = array_merge( $base, $override );
                $current = self::query_args( $target ) === self::query_args( $filters );
                printf(
                    '<a class="%1$s" href="%2$s" aria-current="%3$s"><b>%4$s</b><span>%5$s</span></a>',
                    esc_attr( trim( 'pharma-hub-card ' . $class . ( $current ? ' current' : '' ) ) ),
                    esc_url( self::url( $target ) ),
                    esc_attr( $current ? 'page' : 'false' ),
                    esc_html( number_format_i18n( $number ) ),
                    esc_html( $label )
                );
            }
            ?>
        </nav>
        <p class="description">
            <?php
            printf(
                /* translators: %d: link-check percentage */
                esc_html__( 'Diferença suspeita: a diferença é de %d%% ou mais. Costuma querer dizer que a página do KuantoKusta é de outro produto, variante ou embalagem; confirme antes de mudar o preço.', 'pharma-hub-plugin' ),
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
     * The filter form, the order and the export button.
     *
     * @param array $filters Current filters.
     * @param int   $shown   Rows shown with these filters.
     * @return void
     */
    private static function render_filters( $filters, $shown ) {
        ?>
        <div class="pharma-hub-tools">
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr( Pharma_Hub_Admin::PAGE ); ?>">
                <input type="hidden" name="tab" value="report">
                <input type="hidden" name="outcome" value="<?php echo esc_attr( $filters['outcome'] ); ?>">
                <?php if ( $filters['easy'] ) : ?>
                    <input type="hidden" name="easy" value="1">
                <?php endif; ?>
                <?php if ( $filters['check'] ) : ?>
                    <input type="hidden" name="check" value="1">
                <?php endif; ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr( $filters['orderby'] ); ?>">
                <input type="hidden" name="order" value="<?php echo esc_attr( $filters['order'] ); ?>">
                <label class="screen-reader-text" for="pharma-hub-state"><?php esc_html_e( 'Ofertas a mostrar', 'pharma-hub-plugin' ); ?></label>
                <select name="state" id="pharma-hub-state">
                    <option value="active" <?php selected( $filters['state'], 'active' ); ?>><?php esc_html_e( 'Com stock no KuantoKusta', 'pharma-hub-plugin' ); ?></option>
                    <option value="all" <?php selected( $filters['state'], 'all' ); ?>><?php esc_html_e( 'Incluir sem stock e retiradas', 'pharma-hub-plugin' ); ?></option>
                </select>
                <label><input type="checkbox" name="unlinked" value="1" <?php checked( $filters['unlinked'] ); ?>> <?php esc_html_e( 'Só sem vínculo', 'pharma-hub-plugin' ); ?></label>
                <label><input type="checkbox" name="shipping" value="1" <?php checked( $filters['shipping'] ); ?>> <?php esc_html_e( 'Comparar com portes', 'pharma-hub-plugin' ); ?></label>
                <button type="submit" class="button"><?php esc_html_e( 'Aplicar', 'pharma-hub-plugin' ); ?></button>
            </form>
            <span class="count">
                <?php
                /* translators: %d: number of products shown */
                echo esc_html( sprintf( _n( '%d produto', '%d produtos', $shown, 'pharma-hub-plugin' ), $shown ) );
                echo ' · ';
                if ( 'priority' === $filters['orderby'] ) {
                    esc_html_e( 'ajustes fáceis primeiro, depois da menor para a maior diferença', 'pharma-hub-plugin' );
                } else {
                    printf(
                        '<a href="%s">%s</a>',
                        esc_url(
                            self::url(
                                array_merge(
                                    $filters,
                                    array(
                                        'orderby' => 'priority',
                                        'order'   => 'asc',
                                    )
                                )
                            )
                        ),
                        esc_html__( 'Voltar à ordem recomendada', 'pharma-hub-plugin' )
                    );
                }
                ?>
            </span>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-left: auto">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_EXPORT ); ?>">
                <?php wp_nonce_field( self::ACTION_EXPORT ); ?>
                <?php foreach ( self::query_args( $filters ) as $key => $value ) : ?>
                    <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
                <?php endforeach; ?>
                <button type="submit" class="button"><?php esc_html_e( 'Transferir CSV', 'pharma-hub-plugin' ); ?></button>
            </form>
        </div>
        <?php
    }

    /**
     * The table.
     *
     * Six columns: the lowest price carries its store under it, and the
     * difference carries the percentage, so the eye goes to the difference,
     * which is what decides an action.
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
        <div class="pharma-hub-scroll">
        <table class="pharma-hub-table">
            <thead>
                <tr>
                    <th scope="col"><?php self::sort_link( __( 'Produto', 'pharma-hub-plugin' ), 'name', $filters ); ?></th>
                    <th scope="col" class="num"><?php self::sort_link( $shipping ? $price . ' ' . __( 'com portes', 'pharma-hub-plugin' ) : $price, 'store_price', $filters ); ?></th>
                    <th scope="col" class="num"><?php echo esc_html( $shipping ? __( 'Menor total com portes', 'pharma-hub-plugin' ) : __( 'Menor preço', 'pharma-hub-plugin' ) ); ?></th>
                    <th scope="col" class="num"><?php self::sort_link( __( 'Diferença', 'pharma-hub-plugin' ), 'difference', $filters ); ?></th>
                    <?php if ( ! $shipping ) : ?>
                        <th scope="col"><?php esc_html_e( 'Posição', 'pharma-hub-plugin' ); ?></th>
                    <?php endif; ?>
                    <th scope="col"><?php esc_html_e( 'Situação', 'pharma-hub-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( ! $rows ) : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'Nenhum produto com estes filtros.', 'pharma-hub-plugin' ); ?></td></tr>
                <?php endif; ?>
                <?php
                foreach ( $rows as $row ) :
                    $id         = (string) $row['offerId'];
                    $product    = isset( $products[ $id ] ) ? $products[ $id ] : null;
                    $class      = ! empty( $row['checkLink'] ) ? 'is-check' : ( ! empty( $row['easyAdjust'] ) ? 'is-easy' : '' );
                    $own        = $shipping ? $row['storeTotalCents'] : $row['storePriceCents'];
                    $lowest     = $shipping ? $row['lowestTotalCents'] : $row['lowestPriceCents'];
                    $lowest_by  = (string) ( $shipping ? $row['lowestTotalStoreName'] : $row['lowestStoreName'] );
                    $difference = $shipping ? $row['totalDifferenceCents'] : $row['differenceCents'];
                    $direction  = null === $difference || 0 === $difference ? '' : ( $difference > 0 ? 'is-up' : 'is-down' );
                    $suggestion = $shipping ? null : Pharma_Hub_Report::price_to_be_cheapest( $row );
                    $marker     = Pharma_Hub_Report::position_percent( $row['storePosition'], $row['storeCount'] );
                    ?>
                    <tr class="<?php echo esc_attr( $class ); ?>">
                        <td>
                            <?php if ( $product ) : ?>
                                <a class="name" href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $product->get_name() ) ); ?></a>
                            <?php else : ?>
                                <span class="name"><?php echo esc_html( $row['name'] ); ?></span>
                                <span class="pharma-hub-pill"><?php esc_html_e( 'sem vínculo', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                            <span class="sub">
                                <?php
                                if ( $row['sku'] ) {
                                    /* translators: %s: SKU */
                                    echo esc_html( sprintf( __( 'REF %s', 'pharma-hub-plugin' ), $row['sku'] ) ) . ' · ';
                                }
                                ?>
                                <a href="<?php echo esc_url( $row['productUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'ver no KuantoKusta', 'pharma-hub-plugin' ); ?></a>
                            </span>
                        </td>
                        <td class="num main"><?php echo esc_html( Pharma_Hub_Format::money( $own ) ); ?></td>
                        <td class="num">
                            <?php if ( null !== $lowest ) : ?>
                                <span class="main"><?php echo esc_html( Pharma_Hub_Format::money( $lowest ) ); ?></span>
                                <span class="sub"><?php echo esc_html( $lowest_by ); ?></span>
                            <?php elseif ( 'only_store' === $row['outcome'] ) : ?>
                                <span class="none"><?php esc_html_e( 'sem outras lojas', 'pharma-hub-plugin' ); ?></span>
                            <?php else : ?>
                                <span class="none">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="num">
                            <?php if ( null !== $difference ) : ?>
                                <span class="diff <?php echo esc_attr( $direction ); ?>"><?php echo esc_html( Pharma_Hub_Format::money( $difference, true ) ); ?></span>
                                <?php if ( ! $shipping && null !== $row['differencePercent'] ) : ?>
                                    <span class="sub"><?php echo esc_html( Pharma_Hub_Format::percent( $row['differencePercent'] ) ); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="none">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ( ! $shipping ) : ?>
                            <td>
                                <?php
                                if ( null !== $row['storePosition'] ) {
                                    /* translators: 1: position, 2: number of stores */
                                    echo esc_html( sprintf( __( '%1$d.º de %2$d', 'pharma-hub-plugin' ), $row['storePosition'], $row['storeCount'] ) );
                                }
                                ?>
                                <?php if ( null !== $marker ) : ?>
                                    <?php // Whole numbers only: the marker is 8px wide and must stay inside the track. ?>
                                    <div class="pharma-hub-track" aria-hidden="true"><i style="left: calc(<?php echo (int) $marker; ?>% - <?php echo (int) round( 8 * $marker / 100 ); ?>px)"></i></div>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <?php if ( ! empty( $row['easyAdjust'] ) ) : ?>
                                <span class="pharma-hub-pill easy"><?php esc_html_e( 'Ajuste fácil', 'pharma-hub-plugin' ); ?></span>
                            <?php else : ?>
                                <span class="pharma-hub-pill <?php echo esc_attr( $row['outcome'] ); ?>"><?php echo esc_html( isset( $labels[ $row['outcome'] ] ) ? $labels[ $row['outcome'] ] : $row['outcome'] ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $row['checkLink'] ) ) : ?>
                                <span class="pharma-hub-pill check"><?php esc_html_e( 'Diferença suspeita', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! empty( $row['stale'] ) ) : ?>
                                <span class="pharma-hub-pill" title="<?php echo esc_attr( Pharma_Hub_Format::time( $row['comparedAt'] ) ); ?>"><?php esc_html_e( 'coleta anterior', 'pharma-hub-plugin' ); ?></span>
                            <?php endif; ?>
                            <?php if ( null !== $suggestion ) : ?>
                                <span class="sub">
                                    <?php
                                    /* translators: %s: amount of money */
                                    echo esc_html( sprintf( __( '%s para ser a mais barata', 'pharma-hub-plugin' ), Pharma_Hub_Format::money( $suggestion ) ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
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
        if ( 'priority' !== $filters['orderby'] ) {
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
