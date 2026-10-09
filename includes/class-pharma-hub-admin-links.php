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
     * Longest search text kept, in bytes.
     */
    const MAX_TERM = 100;

    /**
     * The summary cards, by the filter each one leads to.
     *
     * @return array
     */
    private static function cards() {
        return array(
            'todo'   => __( 'Por resolver', 'pharma-hub-plugin' ),
            'all'    => __( 'Todas as ofertas', 'pharma-hub-plugin' ),
            'sku'    => __( 'Vinculadas pelo SKU', 'pharma-hub-plugin' ),
            'ean'    => __( 'Pelo EAN', 'pharma-hub-plugin' ),
            'url'    => __( 'Pelo endereço na loja', 'pharma-hub-plugin' ),
            'manual' => __( 'Escolhidas à mão', 'pharma-hub-plugin' ),
        );
    }

    /**
     * Whether a key names a list.
     *
     * "linked" has no card: it is kept so that addresses saved before the
     * cards existed still open a list.
     *
     * @param string $filter Key from a request.
     * @return bool
     */
    private static function is_filter( $filter ) {
        return 'linked' === $filter || array_key_exists( $filter, self::cards() );
    }

    /**
     * Whether a link belongs to a list.
     *
     * @param array  $link   Resolved link.
     * @param string $filter List.
     * @return bool
     */
    private static function in_filter( $link, $filter ) {
        $linked = 'linked' === $link['state'];
        switch ( $filter ) {
            case 'all':
                return true;
            case 'todo':
                return ! $linked;
            case 'linked':
                return $linked;
            default:
                return $linked && $filter === $link['method'];
        }
    }

    /**
     * The list asked for in the address, or an empty string.
     *
     * @return string
     */
    private static function asked_filter() {
        $filter = isset( $_GET['links'] ) ? sanitize_key( wp_unslash( $_GET['links'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks a list to show.
        return self::is_filter( $filter ) ? $filter : '';
    }

    /**
     * The text searched for in the address, or an empty string.
     *
     * @return string
     */
    private static function asked_term() {
        return self::clean_term( isset( $_GET['s'] ) ? wp_unslash( $_GET['s'] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Only narrows the list shown; sanitized by clean_term().
    }

    /**
     * A search text from a request, as one short line.
     *
     * @param mixed $term Value from a request.
     * @return string
     */
    private static function clean_term( $term ) {
        return is_string( $term ) ? substr( sanitize_text_field( $term ), 0, self::MAX_TERM ) : '';
    }

    /**
     * Text without accents and in lower case, so "algodao" finds "Algodão".
     *
     * @param string $text Text.
     * @return string
     */
    private static function fold( $text ) {
        return strtolower( remove_accents( (string) $text ) );
    }

    /**
     * What the search looks at: the offer's name, REF and EAN, and the name
     * and REF of its product in the store.
     *
     * @param array           $offer   Offer from the hub.
     * @param WC_Product|null $product Its product, when there is one.
     * @return array
     */
    private static function searchable( $offer, $product ) {
        $texts = array(
            isset( $offer['name'] ) ? $offer['name'] : '',
            isset( $offer['sku'] ) ? $offer['sku'] : '',
            isset( $offer['ean'] ) ? $offer['ean'] : '',
        );
        if ( $product ) {
            $texts[] = $product->get_name();
            $texts[] = $product->get_sku();
        }
        return array_map( array( __CLASS__, 'fold' ), array_filter( $texts, 'is_scalar' ) );
    }

    /**
     * The address of a list, keeping the search.
     *
     * @param string $filter List.
     * @param string $term   Text searched for.
     * @return string
     */
    private static function url( $filter, $term ) {
        $args = array( 'links' => $filter );
        if ( '' !== $term ) {
            $args['s'] = rawurlencode( $term );
        }
        return Pharma_Hub_Admin::tab_url( 'links', $args );
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
                esc_url( Pharma_Hub_Admin::connection_url() ),
                esc_html__( 'Configurar a ligação', 'pharma-hub-plugin' )
            );
            return;
        }

        try {
            $offers = $client->get_all_offers();
        } catch ( Pharma_Hub_Error $error ) {
            printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( Pharma_Hub_Admin::error_message( $error ) ) );
            return;
        }

        self::render_list( $offers, Pharma_Hub_Links::resolve( $offers ), self::asked_filter(), self::asked_term() );
    }

    /**
     * Renders the offers with their links.
     *
     * @param array  $offers Offers from the hub.
     * @param array  $links  Their resolved links, by offer id.
     * @param string $filter Filter asked for, or an empty string for the default one.
     * @param string $term   Text searched for, or an empty string.
     * @return void
     */
    private static function render_list( $offers, $links, $filter, $term ) {
        $counts = self::count( $links );
        if ( '' === $filter ) {
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

        $rows = array();
        foreach ( $offers as $offer ) {
            $offer_id = (string) $offer['id'];
            if ( ! isset( $links[ $offer_id ] ) || ! self::in_filter( $links[ $offer_id ], $filter ) ) {
                continue;
            }
            $product = $links[ $offer_id ]['product_id'] ? wc_get_product( $links[ $offer_id ]['product_id'] ) : null;
            if ( '' !== $term && ! Pharma_Hub_Linker::matches_search( self::fold( $term ), self::searchable( $offer, $product ) ) ) {
                continue;
            }
            $rows[] = array( $offer, $links[ $offer_id ], $product ? $product : null );
        }

        self::render_status( count( $offers ), $counts['todo'] );
        self::render_cards( count( $offers ), $counts, $filter, $term );
        self::render_search( $filter, $term, count( $rows ) );
        ?>
        <div class="pharma-hub-scroll">
            <table class="pharma-hub-table">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Oferta no KuantoKusta', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Produto na loja', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Vinculado por', 'pharma-hub-plugin' ); ?></th>
                        <th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Ações', 'pharma-hub-plugin' ); ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ( $rows as $row ) {
                        self::render_row( $row[0], $row[1], $row[2], $filter, $term );
                    }
                    if ( ! $rows ) {
                        if ( '' !== $term ) {
                            /* translators: %s: text searched for */
                            $empty = sprintf( __( 'Nenhuma oferta desta lista corresponde a "%s".', 'pharma-hub-plugin' ), $term );
                        } elseif ( 'todo' === $filter ) {
                            $empty = __( 'Não há nada por resolver.', 'pharma-hub-plugin' );
                        } else {
                            $empty = __( 'Nenhuma oferta nesta lista.', 'pharma-hub-plugin' );
                        }
                        printf( '<tr><td colspan="4" class="none">%s</td></tr>', esc_html( $empty ) );
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <p class="description"><?php esc_html_e( 'O vínculo diz a que produto da loja corresponde cada oferta do KuantoKusta. É procurado pelo SKU, depois pelo EAN e por fim pelo endereço do produto na loja.', 'pharma-hub-plugin' ); ?></p>
        <?php
    }

    /**
     * The strip at the top: is there anything for a person to do.
     *
     * @param int $total Number of offers.
     * @param int $todo  Offers without a product.
     * @return void
     */
    private static function render_status( $total, $todo ) {
        if ( 0 === $total ) {
            $pill  = array( '', __( 'Sem ofertas', 'pharma-hub-plugin' ) );
            $title = __( 'O hub ainda não tem ofertas do KuantoKusta desta loja', 'pharma-hub-plugin' );
            $text  = __( 'Aparecem aqui depois de o hub as copiar do KuantoKusta.', 'pharma-hub-plugin' );
        } elseif ( 0 === $todo ) {
            $pill = array( 'ok', __( 'Tudo vinculado', 'pharma-hub-plugin' ) );
            /* translators: %d: number of offers */
            $title = sprintf( _n( '%d oferta no KuantoKusta, com um produto da loja', 'As %d ofertas do KuantoKusta têm um produto da loja', $total, 'pharma-hub-plugin' ), $total );
            $text  = __( 'Não há nada por resolver. Só precisa de vir aqui se um vínculo estiver errado.', 'pharma-hub-plugin' );
        } else {
            /* translators: %d: number of offers */
            $pill = array( 'warn', sprintf( __( '%d por resolver', 'pharma-hub-plugin' ), $todo ) );
            /* translators: %d: number of offers */
            $title = sprintf( _n( '%d oferta ainda não tem um produto da loja', '%d ofertas ainda não têm um produto da loja', $todo, 'pharma-hub-plugin' ), $todo );
            $text  = __( 'Escolha o produto de cada uma na lista "Por resolver".', 'pharma-hub-plugin' );
        }
        ?>
        <div class="pharma-hub-head">
            <span class="pharma-hub-pill <?php echo esc_attr( $pill[0] ); ?>"><?php echo esc_html( $pill[1] ); ?></span>
            <span class="grow">
                <strong><?php echo esc_html( $title ); ?></strong>
                <span class="sub"><?php echo esc_html( $text ); ?></span>
            </span>
        </div>
        <?php
    }

    /**
     * The numbers, each a way into its list.
     *
     * @param int    $total  Number of offers.
     * @param array  $counts From count().
     * @param string $filter Current filter.
     * @param string $term   Text searched for.
     * @return void
     */
    private static function render_cards( $total, $counts, $filter, $term ) {
        $numbers = array_merge( $counts, array( 'all' => $total ) );
        echo '<div class="pharma-hub-cards">';
        foreach ( self::cards() as $key => $label ) {
            printf(
                '<a class="pharma-hub-card%1$s" href="%2$s"%3$s><b>%4$d</b><span>%5$s</span></a>',
                $key === $filter ? ' current' : '',
                esc_url( self::url( $key, $term ) ),
                $key === $filter ? ' aria-current="true"' : '',
                (int) $numbers[ $key ],
                esc_html( $label )
            );
        }
        echo '</div>';
    }

    /**
     * The search by name, REF or EAN.
     *
     * @param string $filter Current filter.
     * @param string $term   Text searched for.
     * @param int    $shown  Offers in the table.
     * @return void
     */
    private static function render_search( $filter, $term, $shown ) {
        ?>
        <div class="pharma-hub-tools">
            <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                <input type="hidden" name="page" value="<?php echo esc_attr( Pharma_Hub_Admin::PAGE ); ?>">
                <input type="hidden" name="tab" value="links">
                <input type="hidden" name="links" value="<?php echo esc_attr( $filter ); ?>">
                <label for="pharma-hub-links-search"><strong><?php esc_html_e( 'Procurar', 'pharma-hub-plugin' ); ?></strong></label>
                <input type="search" id="pharma-hub-links-search" name="s" value="<?php echo esc_attr( $term ); ?>" maxlength="<?php echo (int) self::MAX_TERM; ?>" placeholder="<?php esc_attr_e( 'Nome, REF ou EAN', 'pharma-hub-plugin' ); ?>" style="width: 300px; max-width: 100%">
                <button type="submit" class="button"><?php esc_html_e( 'Procurar', 'pharma-hub-plugin' ); ?></button>
                <?php if ( '' !== $term ) : ?>
                    <a href="<?php echo esc_url( self::url( $filter, '' ) ); ?>"><?php esc_html_e( 'Limpar', 'pharma-hub-plugin' ); ?></a>
                <?php endif; ?>
            </form>
            <span class="count">
                <?php
                /* translators: %d: number of offers */
                echo esc_html( sprintf( _n( '%d oferta', '%d ofertas', $shown, 'pharma-hub-plugin' ), $shown ) );
                ?>
            </span>
        </div>
        <?php
    }

    /**
     * Renders one offer.
     *
     * @param array           $offer   Offer from the hub.
     * @param array           $link    Its resolved link.
     * @param WC_Product|null $product The product of the link, when there is one.
     * @param string          $filter  Current filter, to come back to it.
     * @param string          $term    Text searched for, to come back to it.
     * @return void
     */
    private static function render_row( $offer, $link, $product, $filter, $term ) {
        $offer_id = (string) $offer['id'];
        $linked   = 'linked' === $link['state'];
        $ids      = array();
        if ( ! empty( $offer['sku'] ) ) {
            /* translators: %s: SKU */
            $ids[] = sprintf( __( 'REF %s', 'pharma-hub-plugin' ), $offer['sku'] );
        }
        if ( ! empty( $offer['ean'] ) ) {
            /* translators: %s: EAN */
            $ids[] = sprintf( __( 'EAN %s', 'pharma-hub-plugin' ), $offer['ean'] );
        }
        if ( isset( $offer['listingStatus'] ) && 'delisted' === $offer['listingStatus'] ) {
            $ids[] = __( 'retirada do KuantoKusta', 'pharma-hub-plugin' );
        }
        list( $pill, $how ) = self::describe( $link );
        ?>
        <tr<?php echo $linked ? '' : ' class="is-todo"'; ?>>
            <td>
                <a class="name" href="<?php echo esc_url( $offer['productUrl'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $offer['name'] ); ?></a>
                <span class="sub"><?php echo esc_html( implode( ' · ', $ids ) ); ?></span>
            </td>
            <td>
                <?php if ( ! $product ) : ?>
                    <span class="none">&mdash;</span>
                <?php elseif ( 'rejected' === $link['state'] ) : ?>
                    <del class="main"><?php echo esc_html( $product->get_name() ); ?></del>
                <?php else : ?>
                    <a class="plain" href="<?php echo esc_url( get_edit_post_link( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
                <?php endif; ?>
                <?php if ( $product && '' !== (string) $product->get_sku() ) : ?>
                    <span class="sub">
                        <?php
                        /* translators: %s: SKU */
                        echo esc_html( sprintf( __( 'REF %s', 'pharma-hub-plugin' ), $product->get_sku() ) );
                        ?>
                    </span>
                <?php endif; ?>
            </td>
            <td><span class="pharma-hub-pill <?php echo esc_attr( $pill ); ?>"><?php echo esc_html( $how ); ?></span></td>
            <td class="end">
                <?php
                if ( $linked ) {
                    self::render_button( self::ACTION_REJECT, $offer_id, __( 'Marcar como errado', 'pharma-hub-plugin' ), $filter, $term );
                } else {
                    self::render_choose_form( $offer_id, $filter, $term );
                }
                if ( 'rejected' === $link['state'] || 'manual' === $link['method'] ) {
                    self::render_button( self::ACTION_RESET, $offer_id, __( 'Procurar de novo', 'pharma-hub-plugin' ), $filter, $term );
                }
                ?>
            </td>
        </tr>
        <?php
    }

    /**
     * How the offer is linked: the kind of pill and its words.
     *
     * @param array $link Resolved link.
     * @return array array( pill class, text ).
     */
    private static function describe( $link ) {
        $keys = array(
            'sku'    => __( 'SKU', 'pharma-hub-plugin' ),
            'ean'    => __( 'EAN', 'pharma-hub-plugin' ),
            'url'    => __( 'Endereço na loja', 'pharma-hub-plugin' ),
            'manual' => __( 'Escolha à mão', 'pharma-hub-plugin' ),
        );
        $key = isset( $keys[ (string) $link['method'] ] ) ? $keys[ (string) $link['method'] ] : '';

        switch ( $link['state'] ) {
            case 'linked':
                return array( 'sku' === $link['method'] ? 'sku' : '', $key );
            case 'rejected':
                return array( 'warn', __( 'Marcado como errado', 'pharma-hub-plugin' ) );
            case 'ambiguous':
                /* translators: %s: the key that found several products, e.g. "SKU" */
                return array( 'warn', sprintf( __( 'Mais de um produto com este %s', 'pharma-hub-plugin' ), $key ) );
            default:
                return array( 'warn', __( 'Nenhum produto encontrado', 'pharma-hub-plugin' ) );
        }
    }

    /**
     * A one-button form for an action on an offer, shown as a quiet link.
     *
     * @param string $action   Action name.
     * @param string $offer_id Hub offer id.
     * @param string $label    Button text.
     * @param string $filter   Current filter.
     * @param string $term     Text searched for.
     * @return void
     */
    private static function render_button( $action, $offer_id, $label, $filter, $term ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
            <input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer_id ); ?>">
            <?php self::render_return_fields( $filter, $term ); ?>
            <?php wp_nonce_field( $action ); ?>
            <button type="submit" class="pharma-hub-quiet"><?php echo esc_html( $label ); ?></button>
        </form>
        <?php
    }

    /**
     * The form to choose the product of an offer, with WooCommerce's search.
     *
     * @param string $offer_id Hub offer id.
     * @param string $filter   Current filter.
     * @param string $term     Text searched for.
     * @return void
     */
    private static function render_choose_form( $offer_id, $filter, $term ) {
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CHOOSE ); ?>">
            <input type="hidden" name="offer_id" value="<?php echo esc_attr( $offer_id ); ?>">
            <?php self::render_return_fields( $filter, $term ); ?>
            <?php wp_nonce_field( self::ACTION_CHOOSE ); ?>
            <select class="wc-product-search" name="product_id" style="width: 280px" data-placeholder="<?php esc_attr_e( 'Procurar produto…', 'pharma-hub-plugin' ); ?>" data-action="woocommerce_json_search_products_and_variations"></select>
            <button type="submit" class="button"><?php esc_html_e( 'Vincular', 'pharma-hub-plugin' ); ?></button>
        </form>
        <?php
    }

    /**
     * Carries the list and the search through a form, to come back to them.
     *
     * @param string $filter Current filter.
     * @param string $term   Text searched for.
     * @return void
     */
    private static function render_return_fields( $filter, $term ) {
        printf( '<input type="hidden" name="links" value="%s">', esc_attr( $filter ) );
        if ( '' !== $term ) {
            printf( '<input type="hidden" name="s" value="%s">', esc_attr( $term ) );
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
        // The nonce was checked by the caller; these only pick the list to return to.
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $filter = isset( $_POST['links'] ) ? sanitize_key( wp_unslash( $_POST['links'] ) ) : '';
        $term   = self::clean_term( isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : '' );
        // phpcs:enable
        $args = self::is_filter( $filter ) ? array( 'links' => $filter ) : array();
        if ( '' !== $term ) {
            $args['s'] = rawurlencode( $term );
        }
        Pharma_Hub_Admin::redirect_back( array( $notice ), 'links', $args );
    }
}
