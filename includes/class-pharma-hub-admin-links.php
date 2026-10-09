<?php
/**
 * The "Vínculos" tab: which WooCommerce product each KuantoKusta offer is.
 *
 * Lists the store's offers as the hub last copied them, with the product
 * each one is linked to and how. A person can mark a link as wrong, choose
 * the right product with WooCommerce's own product search, or let the
 * plugin look again.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Renders the links tab and handles its actions.
 */
class Pharma_Hub_Admin_Links {

    const ACTION_CHOOSE = 'pharma_hub_link_choose';
    const ACTION_REJECT = 'pharma_hub_link_reject';
    const ACTION_RESET  = 'pharma_hub_link_reset';

    /**
     * Filters of the list, by key.
     *
     * @return array
     */
    private static function filters() {
        return array(
            'todo'   => __( 'Por resolver', 'pharma-hub-plugin' ),
            'linked' => __( 'Vinculadas', 'pharma-hub-plugin' ),
            'all'    => __( 'Todas', 'pharma-hub-plugin' ),
        );
    }

    /**
     * Hooks the tab's actions.
     *
     * @return void
     */
    public static function register() {
        add_action( 'admin_post_' . self::ACTION_CHOOSE, array( __CLASS__, 'handle_choose' ) );
        add_action( 'admin_post_' . self::ACTION_REJECT, array( __CLASS__, 'handle_reject' ) );
        add_action( 'admin_post_' . self::ACTION_RESET, array( __CLASS__, 'handle_reset' ) );
    }

    /**
     * Links an offer to the product a person chose.
     *
     * @return void
     */
    public static function handle_choose() {
        Pharma_Hub_Admin::check_capability();
        check_admin_referer( self::ACTION_CHOOSE );

        $offer_id   = self::posted_offer_id();
        $product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
        $product    = $product_id ? wc_get_product( $product_id ) : null;
        if ( ! $product || 'trash' === $product->get_status() ) {
            self::back( array( 'error', __( 'Escolha um produto da loja.', 'pharma-hub-plugin' ) ) );
        }

        Pharma_Hub_Links::choose( $offer_id, $product->get_id(), get_current_user_id() );
        self::back(
            array(
                'success',
                sprintf(
                    /* translators: %s: product name */
                    __( 'Oferta vinculada a "%s".', 'pharma-hub-plugin' ),
                    wp_strip_all_tags( $product->get_formatted_name() )
                ),
            )
        );
    }

    /**
     * Marks a link as wrong.
     *
     * @return void
     */
    public static function handle_reject() {
        Pharma_Hub_Admin::check_capability();
        check_admin_referer( self::ACTION_REJECT );

        $offer_id = self::posted_offer_id();
        if ( ! Pharma_Hub_Links::reject( $offer_id, get_current_user_id() ) ) {
            self::back( array( 'error', __( 'Esta oferta não tem vínculo para marcar como errado.', 'pharma-hub-plugin' ) ) );
        }
        self::back( array( 'success', __( 'Vínculo marcado como errado. Escolha o produto certo.', 'pharma-hub-plugin' ) ) );
    }

    /**
     * Forgets a link, so the plugin looks for the product again.
     *
     * @return void
     */
    public static function handle_reset() {
        Pharma_Hub_Admin::check_capability();
        check_admin_referer( self::ACTION_RESET );

        Pharma_Hub_Links::delete( self::posted_offer_id() );
        self::back( array( 'success', __( 'O vínculo foi apagado e a oferta foi procurada de novo.', 'pharma-hub-plugin' ) ) );
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

        try {
            $offers = $client->get_all_offers();
        } catch ( Pharma_Hub_Error $error ) {
            printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( Pharma_Hub_Admin::error_message( $error ) ) );
            return;
        }

        $links  = Pharma_Hub_Links::resolve( $offers );
        $counts = self::count( $links );
        $filter = isset( $_GET['links'] ) ? sanitize_key( wp_unslash( $_GET['links'] ) ) : '';
        if ( ! array_key_exists( $filter, self::filters() ) ) {
            $filter = $counts['todo'] > 0 ? 'todo' : 'all';
        }

        // Offers that need a person first, then the rest by name.
        usort(
            $offers,
            function ( $a, $b ) use ( $links ) {
                $rank = function ( $offer ) use ( $links ) {
                    $state = isset( $links[ (string) $offer['id'] ]['state'] ) ? $links[ (string) $offer['id'] ]['state'] : 'none';
                    return 'linked' === $state ? 1 : 0;
                };
                $by_rank = $rank( $a ) - $rank( $b );
                return 0 !== $by_rank ? $by_rank : strcasecmp( (string) $a['name'], (string) $b['name'] );
            }
        );
        ?>
        <p>
            <?php
            printf(
                /* translators: 1: offers, 2: by SKU, 3: by EAN, 4: by store address, 5: chosen by a person, 6: to resolve */
                esc_html__( '%1$d ofertas no KuantoKusta: %2$d vinculadas pelo SKU, %3$d pelo EAN, %4$d pelo endereço na loja e %5$d escolhidas à mão. %6$d por resolver.', 'pharma-hub-plugin' ),
                (int) count( $offers ),
                (int) $counts['sku'],
                (int) $counts['ean'],
                (int) $counts['url'],
                (int) $counts['manual'],
                (int) $counts['todo']
            );
            ?>
        </p>

        <ul class="subsubsub">
            <?php
            $items = array();
            foreach ( self::filters() as $key => $label ) {
                $items[] = sprintf(
                    '<li><a href="%s"%s>%s</a></li>',
                    esc_url( Pharma_Hub_Admin::tab_url( 'links', array( 'links' => $key ) ) ),
                    $key === $filter ? ' class="current" aria-current="page"' : '',
                    esc_html( $label )
                );
            }
            echo implode( ' | ', $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
            ?>
        </ul>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e( 'Oferta no KuantoKusta', 'pharma-hub-plugin' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Produto na loja', 'pharma-hub-plugin' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Como', 'pharma-hub-plugin' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Ações', 'pharma-hub-plugin' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $shown = 0;
                foreach ( $offers as $offer ) {
                    $offer_id = (string) $offer['id'];
                    if ( ! isset( $links[ $offer_id ] ) ) {
                        continue;
                    }
                    $link   = $links[ $offer_id ];
                    $linked = 'linked' === $link['state'];
                    if ( ( 'todo' === $filter && $linked ) || ( 'linked' === $filter && ! $linked ) ) {
                        continue;
                    }
                    ++$shown;
                    self::render_row( $offer, $link );
                }
                if ( 0 === $shown ) {
                    printf( '<tr><td colspan="4">%s</td></tr>', esc_html__( 'Nenhuma oferta nesta lista.', 'pharma-hub-plugin' ) );
                }
                ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Renders one offer.
     *
     * @param array $offer Offer from the hub.
     * @param array $link  Its resolved link.
     * @return void
     */
    private static function render_row( $offer, $link ) {
        $offer_id = (string) $offer['id'];
        $product  = $link['product_id'] ? wc_get_product( $link['product_id'] ) : null;
        $ids      = array();
        if ( ! empty( $offer['sku'] ) ) {
            /* translators: %s: SKU */
            $ids[] = sprintf( __( 'REF %s', 'pharma-hub-plugin' ), $offer['sku'] );
        }
        if ( ! empty( $offer['ean'] ) ) {
            /* translators: %s: EAN */
            $ids[] = sprintf( __( 'EAN %s', 'pharma-hub-plugin' ), $offer['ean'] );
        }
        ?>
        <tr>
            <td>
                <a href="<?php echo esc_url( $offer['productUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $offer['name'] ); ?></a>
                <?php if ( isset( $offer['listingStatus'] ) && 'delisted' === $offer['listingStatus'] ) : ?>
                    <em>(<?php esc_html_e( 'retirada do KuantoKusta', 'pharma-hub-plugin' ); ?>)</em>
                <?php endif; ?>
                <br><small><?php echo esc_html( implode( ' · ', $ids ) ); ?></small>
            </td>
            <td>
                <?php if ( $product ) : ?>
                    <?php if ( 'rejected' === $link['state'] ) : ?>
                        <del><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></del>
                    <?php else : ?>
                        <a href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ); ?>"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></a>
                    <?php endif; ?>
                <?php else : ?>
                    &mdash;
                <?php endif; ?>
            </td>
            <td><?php echo esc_html( self::describe( $link ) ); ?></td>
            <td>
                <?php
                if ( 'linked' === $link['state'] ) {
                    self::render_button( self::ACTION_REJECT, $offer_id, __( 'Marcar como errado', 'pharma-hub-plugin' ) );
                } else {
                    self::render_choose_form( $offer_id );
                }
                if ( 'rejected' === $link['state'] || 'manual' === $link['method'] ) {
                    self::render_button( self::ACTION_RESET, $offer_id, __( 'Procurar de novo', 'pharma-hub-plugin' ) );
                }
                ?>
            </td>
        </tr>
        <?php
    }

    /**
     * How the offer is linked, in words.
     *
     * @param array $link Resolved link.
     * @return string
     */
    private static function describe( $link ) {
        $keys = array(
            'sku'    => __( 'pelo SKU', 'pharma-hub-plugin' ),
            'ean'    => __( 'pelo EAN', 'pharma-hub-plugin' ),
            'url'    => __( 'pelo endereço na loja', 'pharma-hub-plugin' ),
            'manual' => __( 'escolhido à mão', 'pharma-hub-plugin' ),
        );
        $key = isset( $keys[ (string) $link['method'] ] ) ? $keys[ (string) $link['method'] ] : '';

        switch ( $link['state'] ) {
            case 'linked':
                return $key;
            case 'rejected':
                return __( 'Marcado como errado', 'pharma-hub-plugin' );
            case 'ambiguous':
                /* translators: %s: how, e.g. "pelo SKU" */
                return sprintf( __( 'Mais de um produto %s: escolha qual', 'pharma-hub-plugin' ), $key );
            default:
                return __( 'Nenhum produto encontrado', 'pharma-hub-plugin' );
        }
    }

    /**
     * A one-button form for an action on an offer.
     *
     * @param string $action   Action name.
     * @param string $offer_id Hub offer id.
     * @param string $label    Button text.
     * @return void
     */
    private static function render_button( $action, $offer_id, $label ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin: 0 4px 4px 0">
            <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
            <input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer_id ); ?>">
            <?php self::render_filter_field(); ?>
            <?php wp_nonce_field( $action ); ?>
            <button type="submit" class="button button-small"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    /**
     * The form to choose the product of an offer, with WooCommerce's search.
     *
     * @param string $offer_id Hub offer id.
     * @return void
     */
    private static function render_choose_form( $offer_id ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display: inline-block; margin: 0 4px 4px 0">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CHOOSE ); ?>">
            <input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer_id ); ?>">
            <?php self::render_filter_field(); ?>
            <?php wp_nonce_field( self::ACTION_CHOOSE ); ?>
            <select class="wc-product-search" name="product_id" style="width: 280px" data-placeholder="<?php esc_attr_e( 'Procurar produto…', 'pharma-hub-plugin' ); ?>" data-action="woocommerce_json_search_products_and_variations"></select>
            <button type="submit" class="button button-small"><?php esc_html_e( 'Vincular', 'pharma-hub-plugin' ); ?></button>
        </form>
        <?php
    }

    /**
     * Carries the current filter through a form, to come back to the same list.
     *
     * @return void
     */
    private static function render_filter_field() {
        $filter = isset( $_GET['links'] ) ? sanitize_key( wp_unslash( $_GET['links'] ) ) : '';
        if ( array_key_exists( $filter, self::filters() ) ) {
            printf( '<input type="hidden" name="links" value="%s">', esc_attr( $filter ) );
        }
    }

    /**
     * How many offers are linked by each key, and how many need a person.
     *
     * @param array $links Resolved links.
     * @return array
     */
    private static function count( $links ) {
        $counts = array(
            'sku'    => 0,
            'ean'    => 0,
            'url'    => 0,
            'manual' => 0,
            'todo'   => 0,
        );
        foreach ( $links as $link ) {
            if ( 'linked' !== $link['state'] ) {
                ++$counts['todo'];
            } elseif ( isset( $counts[ $link['method'] ] ) ) {
                ++$counts[ $link['method'] ];
            }
        }
        return $counts;
    }

    /**
     * The offer id posted by a form, or back to the list when it is not one.
     *
     * @return string
     */
    private static function posted_offer_id() {
        // The nonce was checked by the caller.
        $offer_id = isset( $_POST['offer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['offer_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( ! Pharma_Hub_Links::is_offer_id( $offer_id ) ) {
            self::back( array( 'error', __( 'Oferta desconhecida.', 'pharma-hub-plugin' ) ) );
        }
        return $offer_id;
    }

    /**
     * Returns to the links tab, on the list the person was looking at.
     *
     * @param array $notice array( type, message ).
     * @return void
     */
    private static function back( $notice ) {
        $filter = isset( $_POST['links'] ) ? sanitize_key( wp_unslash( $_POST['links'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only picks the list to return to.
        Pharma_Hub_Admin::redirect_back(
            array( $notice ),
            'links',
            array_key_exists( $filter, self::filters() ) ? array( 'links' => $filter ) : array()
        );
    }
}
