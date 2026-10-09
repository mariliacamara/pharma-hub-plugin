<?php
/**
 * Admin screens.
 *
 * Every screen and action needs the manage_woocommerce capability, and every
 * action checks a nonce. Secret fields are write-only: the token and the
 * KuantoKusta key are never printed back, only their last four characters.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The menu, the pages, the settings and the actions they post to.
 *
 * The hub has a menu of its own, named after the store's brand, with one
 * entry per integration. Today that is KuantoKusta; another integration
 * becomes another entry, with its own settings. What every integration
 * shares, the connection to the hub, has an entry of its own.
 */
class Pharma_Hub_Admin {

    const CAPABILITY = 'manage_woocommerce';

    /**
     * The KuantoKusta page. Also the slug of the menu itself, so its first
     * entry opens it, and addresses saved before the menu moved keep working.
     */
    const PAGE = 'pharma-hub';

    /**
     * The page of the connection to the hub.
     */
    const PAGE_CONNECTION = 'pharma-hub-connection';

    const ACTION_SAVE_CONNECTION = 'pharma_hub_save_connection';
    const ACTION_SEND_KK_KEY     = 'pharma_hub_send_kk_key';
    const ACTION_SAVE_EASY       = 'pharma_hub_save_easy_adjust';
    const ACTION_SAVE_EAN        = 'pharma_hub_save_ean';

    /**
     * Hooks the admin screens.
     *
     * @return void
     */
    public static function register() {
        add_action( 'admin_init', array( 'Pharma_Hub_Links', 'install' ) );
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
        Pharma_Hub_Admin_Links::register();
        Pharma_Hub_Admin_Report::register();
        Pharma_Hub_Admin_Runs::register();
        add_action( 'admin_post_' . self::ACTION_SAVE_CONNECTION, array( __CLASS__, 'handle_save_connection' ) );
        add_action( 'admin_post_' . self::ACTION_SEND_KK_KEY, array( __CLASS__, 'handle_send_kk_key' ) );
        add_action( 'admin_post_' . self::ACTION_SAVE_EASY, array( __CLASS__, 'handle_save_easy_adjust' ) );
        add_action( 'admin_post_' . self::ACTION_SAVE_EAN, array( __CLASS__, 'handle_save_ean' ) );
    }

    /**
     * Adds the hub's own menu, with one entry per integration.
     *
     * @return void
     */
    public static function add_menu() {
        $brand = Pharma_Hub_Settings::brand_name();
        // Right before WooCommerce's own entries.
        add_menu_page( $brand, $brand, self::CAPABILITY, self::PAGE, array( __CLASS__, 'render_page' ), 'dashicons-chart-bar', 40 );
        // The same slug as the menu: this entry replaces the one WordPress
        // would otherwise repeat under the menu's own name.
        add_submenu_page( self::PAGE, 'KuantoKusta', 'KuantoKusta', self::CAPABILITY, self::PAGE, array( __CLASS__, 'render_page' ) );
        add_submenu_page(
            self::PAGE,
            __( 'Ligação ao hub', 'pharma-hub-plugin' ),
            __( 'Ligação ao hub', 'pharma-hub-plugin' ),
            self::CAPABILITY,
            self::PAGE_CONNECTION,
            array( __CLASS__, 'render_connection_page' )
        );
    }

    /**
     * Which of the plugin's pages is being shown, or an empty string.
     *
     * @return string
     */
    private static function current_page() {
        $page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks what to load.
        return in_array( $page, array( self::PAGE, self::PAGE_CONNECTION ), true ) ? $page : '';
    }

    /**
     * Loads WooCommerce's product search on the KuantoKusta page.
     *
     * The page is recognised by its slug, not by the hook suffix: the suffix
     * of a page under a menu is made from the menu's title, which here is
     * the store's brand name and can change.
     *
     * @return void
     */
    public static function enqueue() {
        if ( self::PAGE !== self::current_page() ) {
            return;
        }
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_style( 'woocommerce_admin_styles' );
        if ( 'report' === self::current_tab() ) {
            Pharma_Hub_Admin_Runs::enqueue();
        }
    }

    /**
     * The tabs of the page, by key.
     *
     * @return array
     */
    private static function tabs() {
        return array(
            'report'   => __( 'Relatório', 'pharma-hub-plugin' ),
            'links'    => __( 'Vínculos', 'pharma-hub-plugin' ),
            'settings' => __( 'Definições', 'pharma-hub-plugin' ),
        );
    }

    /**
     * The tab asked for in the URL, or the first one.
     *
     * @return string
     */
    private static function current_tab() {
        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
        return array_key_exists( $tab, self::tabs() ) ? $tab : 'report';
    }

    /**
     * The address of a tab of the page.
     *
     * @param string $tab  Tab key.
     * @param array  $args More query arguments.
     * @return string
     */
    public static function tab_url( $tab, $args = array() ) {
        return add_query_arg(
            array_merge(
                array(
                    'page' => self::PAGE,
                    'tab'  => $tab,
                ),
                $args
            ),
            admin_url( 'admin.php' )
        );
    }

    /**
     * The address of the page of the connection to the hub.
     *
     * @return string
     */
    public static function connection_url() {
        return add_query_arg( array( 'page' => self::PAGE_CONNECTION ), admin_url( 'admin.php' ) );
    }

    /**
     * Renders the KuantoKusta page: title, tabs, notices and the current tab.
     *
     * @return void
     */
    public static function render_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Não tem permissão para ver esta página.', 'pharma-hub-plugin' ), 403 );
        }
        $current = self::current_tab();
        ?>
        <div class="wrap">
            <h1>KuantoKusta</h1>
            <?php self::render_styles(); ?>
            <nav class="nav-tab-wrapper">
                <?php foreach ( self::tabs() as $tab => $label ) : ?>
                    <a href="<?php echo esc_url( self::tab_url( $tab ) ); ?>" class="nav-tab<?php echo $tab === $current ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php
            self::render_notices();
            if ( 'settings' === $current ) {
                self::render_settings_tab();
            } elseif ( 'links' === $current ) {
                Pharma_Hub_Admin_Links::render();
            } else {
                Pharma_Hub_Admin_Report::render();
            }
            ?>
        </div>
        <?php
    }

    /**
     * Saves the hub's address and the token.
     *
     * @return void
     */
    public static function handle_save_connection() {
        self::check_capability();
        check_admin_referer( self::ACTION_SAVE_CONNECTION );
        $notices = array();

        if ( ! Pharma_Hub_Settings::url_from_constant() && isset( $_POST['pharma_hub_url'] ) ) {
            $url = sanitize_text_field( wp_unslash( $_POST['pharma_hub_url'] ) );
            if ( ! Pharma_Hub_Settings::save_url( $url ) ) {
                $notices[] = array(
                    'error',
                    Pharma_Hub_Settings::allow_local()
                        ? __( 'O endereço do hub não é válido.', 'pharma-hub-plugin' )
                        : __( 'O endereço do hub não é válido. Tem de começar por https://.', 'pharma-hub-plugin' ),
                );
            }
        }

        if ( 'constant' !== Pharma_Hub_Settings::token_source() ) {
            if ( ! empty( $_POST['pharma_hub_delete_token'] ) ) {
                Pharma_Hub_Settings::delete_token();
                $notices[] = array( 'success', __( 'O token foi apagado.', 'pharma-hub-plugin' ) );
            } elseif ( isset( $_POST['pharma_hub_token'] ) ) {
                // Not sanitize_text_field: a token is compared exactly, and
                // anything outside its alphabet is refused by the pattern.
                $token = trim( wp_unslash( $_POST['pharma_hub_token'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ( '' !== $token && ! Pharma_Hub_Settings::save_token( $token ) ) {
                    $notices[] = array( 'error', __( 'O token não tem o formato esperado (phk_ seguido de 43 caracteres). Nada foi guardado.', 'pharma-hub-plugin' ) );
                }
            }
        }

        if ( ! $notices ) {
            $notices[] = array( 'success', __( 'Ligação guardada.', 'pharma-hub-plugin' ) );
        }
        self::redirect_back( $notices, 'connection' );
    }

    /**
     * Saves the meta key that holds the EAN.
     *
     * @return void
     */
    public static function handle_save_ean() {
        self::check_capability();
        check_admin_referer( self::ACTION_SAVE_EAN );

        $key = isset( $_POST['pharma_hub_ean_meta_key'] ) ? sanitize_text_field( wp_unslash( $_POST['pharma_hub_ean_meta_key'] ) ) : '';
        if ( ! Pharma_Hub_Settings::save_ean_meta_key( $key ) ) {
            self::redirect_back( array( array( 'error', __( 'O nome do campo do EAN só pode ter letras, números, _ e -. Nada foi alterado.', 'pharma-hub-plugin' ) ) ) );
        }
        self::redirect_back( array( array( 'success', __( 'Campo do EAN guardado.', 'pharma-hub-plugin' ) ) ) );
    }

    /**
     * Hands the KuantoKusta key to the hub. The plugin keeps no copy.
     *
     * @return void
     */
    public static function handle_send_kk_key() {
        self::check_capability();
        check_admin_referer( self::ACTION_SEND_KK_KEY );

        $key = isset( $_POST['pharma_hub_kk_key'] ) ? trim( wp_unslash( $_POST['pharma_hub_kk_key'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A secret passed on as it is; never stored or printed.
        if ( '' === $key || strlen( $key ) > 512 ) {
            self::redirect_back( array( array( 'error', __( 'Escreva a chave da API do KuantoKusta.', 'pharma-hub-plugin' ) ) ) );
        }

        $client = Pharma_Hub_Settings::client();
        if ( null === $client ) {
            self::redirect_back( array( array( 'error', __( 'Configure primeiro o endereço do hub e o token.', 'pharma-hub-plugin' ) ) ), 'connection' );
        }

        try {
            $status = $client->put_credential( $key );
            $notice = array(
                'success',
                sprintf(
                    /* translators: %s: last four characters of the key */
                    __( 'O KuantoKusta aceitou a chave e o hub guardou-a (termina em %s). Este site não guarda nenhuma cópia.', 'pharma-hub-plugin' ),
                    isset( $status['lastFour'] ) ? (string) $status['lastFour'] : '????'
                ),
            );
        } catch ( Pharma_Hub_Error $error ) {
            $notice = array( 'error', self::error_message( $error ) );
        }
        self::redirect_back( array( $notice ) );
    }

    /**
     * Changes the store's "easy adjust" threshold in the hub.
     *
     * @return void
     */
    public static function handle_save_easy_adjust() {
        self::check_capability();
        check_admin_referer( self::ACTION_SAVE_EASY );

        $typed = isset( $_POST['pharma_hub_easy_adjust_cents'] ) ? sanitize_text_field( wp_unslash( $_POST['pharma_hub_easy_adjust_cents'] ) ) : '';
        $cents = Pharma_Hub_Format::cents_from_input( $typed, Pharma_Hub_Client::MAX_EASY_ADJUST_CENTS );
        if ( null === $cents ) {
            self::redirect_back( array( array( 'error', __( 'Escreva o limite em cêntimos, só com algarismos (por exemplo 10). Nada foi alterado.', 'pharma-hub-plugin' ) ) ) );
        }

        $client = Pharma_Hub_Settings::client();
        if ( null === $client ) {
            self::redirect_back( array( array( 'error', __( 'Configure primeiro o endereço do hub e o token.', 'pharma-hub-plugin' ) ) ), 'connection' );
        }

        try {
            $client->put_easy_adjust( $cents );
            // The cached report still marks offers by the old threshold.
            Pharma_Hub_Report::forget();
            $notice = array(
                'success',
                sprintf(
                    /* translators: %s: amount of money */
                    __( 'Limite do ajuste fácil alterado para %s. O relatório já usa o novo valor.', 'pharma-hub-plugin' ),
                    Pharma_Hub_Format::money( $cents )
                ),
            );
        } catch ( Pharma_Hub_Error $error ) {
            $notice = array( 'error', self::error_message( $error ) );
        }
        self::redirect_back( array( $notice ) );
    }

    /**
     * The look shared by every screen: the status strip, summary cards,
     * pills, tables and settings panels.
     *
     * Every text colour has a contrast of at least 4.5:1 on its ground, and
     * no meaning rests on colour alone: differences keep their sign and each
     * pill says what it is.
     *
     * @return void
     */
    public static function render_styles() {
        ?>
        <style>
            .pharma-hub-head { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 16px; margin: 12px 0; padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; }
            .pharma-hub-head .grow { flex: 1 1 320px; }
            .pharma-hub-head strong { font-size: 14px; }
            .pharma-hub-head .sub { display: block; color: #50575e; }
            .pharma-hub-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; margin: 12px 0; }
            .pharma-hub-card { display: flex; flex-direction: column; gap: 2px; padding: 10px 14px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; color: #50575e; text-decoration: none; }
            .pharma-hub-card:hover, .pharma-hub-card:focus { border-color: #2271b1; color: #1d2327; }
            .pharma-hub-card b { font-size: 22px; line-height: 1.2; font-weight: 600; color: #1d2327; font-variant-numeric: tabular-nums; }
            .pharma-hub-card.is-up b { color: #8a3b00; }
            .pharma-hub-card.is-down b { color: #0b5d3b; }
            .pharma-hub-card.current, .pharma-hub-card.current b, .pharma-hub-card.current:hover { background: #1d5fa0; border-color: #1d5fa0; color: #fff; }
            .pharma-hub-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; margin: 12px 0; }
            .pharma-hub-tools form { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 12px; margin: 0; }
            .pharma-hub-tools .count { color: #50575e; }
            .pharma-hub-scroll { overflow-x: auto; background: #fff; border: 1px solid #c3c4c7; }
            .pharma-hub-table { width: 100%; min-width: 900px; border-collapse: collapse; font-variant-numeric: tabular-nums; }
            .pharma-hub-table th { padding: 10px 14px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7; text-align: left; font-weight: 600; white-space: nowrap; }
            .pharma-hub-table th a { color: #1d2327; text-decoration: none; }
            .pharma-hub-table td { padding: 10px 14px; border-bottom: 1px solid #dcdcde; vertical-align: top; }
            .pharma-hub-table tr:last-child td { border-bottom: 0; }
            .pharma-hub-table .num { text-align: right; white-space: nowrap; }
            .pharma-hub-table .end { text-align: right; }
            .pharma-hub-table .main { font-size: 14px; }
            .pharma-hub-table .name { font-size: 14px; font-weight: 600; text-decoration: none; }
            .pharma-hub-table .plain { font-size: 14px; text-decoration: none; }
            .pharma-hub-table .sub { display: block; margin-top: 2px; color: #50575e; font-size: 12px; }
            .pharma-hub-table .none { color: #50575e; }
            .pharma-hub-table .diff { font-size: 14px; font-weight: 600; }
            .pharma-hub-table .diff.is-up { color: #8a3b00; }
            .pharma-hub-table .diff.is-down { color: #0b5d3b; }
            .pharma-hub-table tr.is-easy td { background: #f0f6fc; }
            .pharma-hub-table tr.is-check td, .pharma-hub-table tr.is-todo td { background: #fcf9e8; }
            .pharma-hub-table form { display: inline-block; margin: 0 0 4px 4px; }
            .pharma-hub-track { position: relative; width: 84px; height: 4px; margin-top: 6px; background: #dcdcde; border-radius: 2px; }
            .pharma-hub-track i { position: absolute; top: -2px; width: 8px; height: 8px; border-radius: 50%; background: #1d2327; }
            .pharma-hub-pill { display: inline-block; margin: 0 4px 4px 0; padding: 2px 10px; border-radius: 999px; background: #f0f0f1; color: #3c434a; font-size: 12px; font-weight: 600; line-height: 1.6; white-space: nowrap; }
            .pharma-hub-pill.easy { background: #1d5fa0; color: #fff; }
            .pharma-hub-pill.more_expensive { background: #fdf0e4; color: #8a3b00; }
            .pharma-hub-pill.cheapest, .pharma-hub-pill.ok { background: #e3f3ea; color: #0b5d3b; }
            .pharma-hub-pill.tied, .pharma-hub-pill.sku { background: #e5eef7; color: #1d4f80; }
            .pharma-hub-pill.check, .pharma-hub-pill.warn { background: #f5e6ab; color: #614200; }
            .pharma-hub-quiet { padding: 4px 6px; background: none; border: 0; color: #2271b1; font: inherit; text-decoration: underline; cursor: pointer; }
            .pharma-hub-quiet:hover, .pharma-hub-quiet:focus { color: #135e96; }
            .pharma-hub-panels { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; align-items: start; margin: 12px 0; }
            .pharma-hub-panel { padding: 16px 20px 20px; background: #fff; border: 1px solid #c3c4c7; }
            .pharma-hub-panel h2 { margin: 0 0 4px; font-size: 16px; }
            .pharma-hub-panel > p { margin: 0 0 12px; color: #50575e; }
            .pharma-hub-panel label { display: block; margin-bottom: 6px; font-weight: 600; }
            .pharma-hub-panel label.inline { display: inline-block; margin: 8px 0 0; font-weight: 400; }
            .pharma-hub-panel .field { margin-bottom: 12px; }
            .pharma-hub-panel .hint { display: block; margin-top: 6px; color: #50575e; font-size: 12px; }
            .pharma-hub-panel .state { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 12px; padding: 10px 12px; background: #f6f7f7; border: 1px solid #dcdcde; }
            .pharma-hub-panel .state .pharma-hub-pill { margin: 0; }
            .pharma-hub-panel dl { display: grid; grid-template-columns: max-content minmax(0, 1fr); gap: 10px 20px; margin: 0; }
            .pharma-hub-panel dt { font-weight: 600; }
            .pharma-hub-panel dd { margin: 0; overflow-wrap: anywhere; }
        </style>
        <?php
    }

    /**
     * Asks the hub who the store is and how its settings stand.
     *
     * @return array{client: Pharma_Hub_Client|null, store: array|null, kk: array|null, easy: int|null, failed: Pharma_Hub_Error|null}
     */
    private static function hub_state() {
        $state = array(
            'client' => Pharma_Hub_Settings::client(),
            'store'  => null,
            'kk'     => null,
            'easy'   => null,
            'failed' => null,
        );
        if ( $state['client'] ) {
            try {
                $state['store'] = $state['client']->get_store();
                Pharma_Hub_Settings::remember_store( $state['store'] );
                $state['kk']   = $state['client']->get_credential();
                $answer        = $state['client']->get_easy_adjust();
                $state['easy'] = isset( $answer['cents'] ) && is_int( $answer['cents'] ) ? $answer['cents'] : null;
            } catch ( Pharma_Hub_Error $error ) {
                $state['failed'] = $error;
            }
        }
        return $state;
    }

    /**
     * The strip at the top of the settings: connected or not, in one line.
     *
     * @param array $state       From hub_state().
     * @param bool  $with_action Offer the way to the connection page when not connected.
     * @return void
     */
    private static function render_connection_strip( $state, $with_action ) {
        ?>
        <div class="pharma-hub-head">
            <?php if ( null === $state['client'] ) : ?>
                <span class="pharma-hub-pill warn"><?php esc_html_e( 'Não ligado', 'pharma-hub-plugin' ); ?></span>
                <span class="grow">
                    <strong><?php esc_html_e( 'Ainda não está ligado ao hub', 'pharma-hub-plugin' ); ?></strong>
                    <span class="sub"><?php esc_html_e( 'Falta o endereço do hub ou o token.', 'pharma-hub-plugin' ); ?></span>
                </span>
                <?php if ( $with_action ) : ?>
                    <a class="button button-primary" href="<?php echo esc_url( self::connection_url() ); ?>"><?php esc_html_e( 'Configurar a ligação', 'pharma-hub-plugin' ); ?></a>
                <?php endif; ?>
            <?php elseif ( $state['failed'] ) : ?>
                <span class="pharma-hub-pill warn"><?php esc_html_e( 'Sem resposta', 'pharma-hub-plugin' ); ?></span>
                <span class="grow">
                    <strong><?php esc_html_e( 'O hub não respondeu como esperado', 'pharma-hub-plugin' ); ?></strong>
                    <span class="sub"><?php echo esc_html( self::error_message( $state['failed'] ) ); ?></span>
                </span>
            <?php else : ?>
                <span class="pharma-hub-pill ok"><?php esc_html_e( 'Ligado', 'pharma-hub-plugin' ); ?></span>
                <span class="grow">
                    <strong>
                        <?php
                        /* translators: %s: store name */
                        echo esc_html( sprintf( __( 'Ligado ao hub como %s', 'pharma-hub-plugin' ), isset( $state['store']['name'] ) ? (string) $state['store']['name'] : '' ) );
                        ?>
                    </strong>
                    <span class="sub">
                        <?php
                        if ( ! empty( $state['kk']['configured'] ) ) {
                            /* translators: %s: last four characters of the key */
                            echo esc_html( sprintf( __( 'A chave do KuantoKusta está configurada no hub (termina em %s).', 'pharma-hub-plugin' ), isset( $state['kk']['lastFour'] ) ? (string) $state['kk']['lastFour'] : '????' ) );
                        } else {
                            esc_html_e( 'A chave do KuantoKusta ainda não está configurada.', 'pharma-hub-plugin' );
                        }
                        ?>
                    </span>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Renders KuantoKusta's settings: one panel per thing, each with its own
     * button, in the order a store changes them.
     *
     * @return void
     */
    private static function render_settings_tab() {
        $state      = self::hub_state();
        $connected  = null !== $state['client'] && null === $state['failed'];
        $easy       = $state['easy'];
        $configured = ! empty( $state['kk']['configured'] );
        $admin_post = admin_url( 'admin-post.php' );

        self::render_connection_strip( $state, true );
        ?>
        <div class="pharma-hub-panels">
            <section class="pharma-hub-panel">
                <h2><?php esc_html_e( 'Ajuste fácil', 'pharma-hub-plugin' ); ?></h2>
                <p><?php esc_html_e( 'O relatório destaca as ofertas em que a loja está mais cara por pouco. Aqui define até quanto conta como pouco.', 'pharma-hub-plugin' ); ?></p>
                <form method="post" action="<?php echo esc_url( $admin_post ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_EASY ); ?>">
                    <?php wp_nonce_field( self::ACTION_SAVE_EASY ); ?>
                    <div class="field">
                        <label for="pharma_hub_easy_adjust_cents"><?php esc_html_e( 'Limite, em cêntimos', 'pharma-hub-plugin' ); ?></label>
                        <input type="number" class="small-text" id="pharma_hub_easy_adjust_cents" name="pharma_hub_easy_adjust_cents" min="0" max="<?php echo esc_attr( Pharma_Hub_Client::MAX_EASY_ADJUST_CENTS ); ?>" step="1" inputmode="numeric" value="<?php echo esc_attr( null === $easy ? '' : $easy ); ?>" <?php disabled( null === $easy ); ?>>
                        <?php if ( null !== $easy ) : ?>
                            <?php
                            /* translators: %s: amount of money */
                            echo esc_html( sprintf( __( 'Agora: mais cara por até %s', 'pharma-hub-plugin' ), Pharma_Hub_Format::money( $easy ) ) );
                            ?>
                        <?php endif; ?>
                        <span class="hint"><?php esc_html_e( 'Com 0, nenhuma oferta é marcada como ajuste fácil.', 'pharma-hub-plugin' ); ?></span>
                    </div>
                    <button type="submit" class="button button-primary" <?php disabled( null === $easy ); ?>><?php esc_html_e( 'Guardar limite', 'pharma-hub-plugin' ); ?></button>
                </form>
            </section>

            <section class="pharma-hub-panel">
                <h2><?php esc_html_e( 'Chave da API do KuantoKusta', 'pharma-hub-plugin' ); ?></h2>
                <p><?php esc_html_e( 'A chave é enviada uma vez para o hub, que a confirma com o KuantoKusta e a guarda cifrada. Este site não guarda nenhuma cópia.', 'pharma-hub-plugin' ); ?></p>
                <?php if ( $connected ) : ?>
                    <div class="state">
                        <?php if ( $configured ) : ?>
                            <span class="pharma-hub-pill ok"><?php esc_html_e( 'Configurada', 'pharma-hub-plugin' ); ?></span>
                            <span>
                                <?php
                                printf(
                                    /* translators: %s: last four characters of the key */
                                    esc_html__( 'Termina em %s', 'pharma-hub-plugin' ),
                                    '<strong>' . esc_html( isset( $state['kk']['lastFour'] ) ? (string) $state['kk']['lastFour'] : '????' ) . '</strong>'
                                );
                                ?>
                            </span>
                        <?php else : ?>
                            <span class="pharma-hub-pill warn"><?php esc_html_e( 'Por configurar', 'pharma-hub-plugin' ); ?></span>
                            <span><?php esc_html_e( 'Sem a chave, o hub não consegue ler as ofertas da loja.', 'pharma-hub-plugin' ); ?></span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url( $admin_post ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SEND_KK_KEY ); ?>">
                    <?php wp_nonce_field( self::ACTION_SEND_KK_KEY ); ?>
                    <div class="field">
                        <label for="pharma_hub_kk_key"><?php echo esc_html( $configured ? __( 'Substituir por uma nova chave', 'pharma-hub-plugin' ) : __( 'Chave', 'pharma-hub-plugin' ) ); ?></label>
                        <input type="password" class="regular-text" id="pharma_hub_kk_key" name="pharma_hub_kk_key" value="" autocomplete="off" spellcheck="false" <?php disabled( ! $connected ); ?>>
                        <?php if ( $configured ) : ?>
                            <span class="hint"><?php esc_html_e( 'Só é preciso quando a chave mudar no KuantoKusta.', 'pharma-hub-plugin' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="button" <?php disabled( ! $connected ); ?>><?php esc_html_e( 'Enviar para o hub', 'pharma-hub-plugin' ); ?></button>
                </form>
            </section>

            <section class="pharma-hub-panel">
                <h2><?php esc_html_e( 'Campo do EAN', 'pharma-hub-plugin' ); ?></h2>
                <p><?php esc_html_e( 'Usado para vincular as ofertas aos produtos. O EAN é procurado primeiro no campo "GTIN, UPC, EAN ou ISBN" do WooCommerce e depois neste campo, de outro plugin.', 'pharma-hub-plugin' ); ?></p>
                <form method="post" action="<?php echo esc_url( $admin_post ); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_EAN ); ?>">
                    <?php wp_nonce_field( self::ACTION_SAVE_EAN ); ?>
                    <div class="field">
                        <label for="pharma_hub_ean_meta_key"><?php esc_html_e( 'Nome do campo', 'pharma-hub-plugin' ); ?></label>
                        <input type="text" class="regular-text code" id="pharma_hub_ean_meta_key" name="pharma_hub_ean_meta_key" value="<?php echo esc_attr( Pharma_Hub_Settings::ean_meta_key() ); ?>" spellcheck="false">
                        <span class="hint"><?php esc_html_e( '_alg_ean é o do "EAN Barcode Generator for WooCommerce". Em branco: só o campo do WooCommerce.', 'pharma-hub-plugin' ); ?></span>
                    </div>
                    <button type="submit" class="button"><?php esc_html_e( 'Guardar campo', 'pharma-hub-plugin' ); ?></button>
                </form>
            </section>
        </div>
        <?php
    }

    /**
     * Renders the page of the connection to the hub, shared by every
     * integration: the address and the token.
     *
     * What is fixed in wp-config.php is shown as information, not as a form
     * that cannot be saved.
     *
     * @return void
     */
    public static function render_connection_page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Não tem permissão para ver esta página.', 'pharma-hub-plugin' ), 403 );
        }
        $state             = self::hub_state();
        $url_from_constant = Pharma_Hub_Settings::url_from_constant();
        $url               = $url_from_constant ? PHARMA_HUB_URL : get_option( Pharma_Hub_Settings::OPTION_URL, '' );
        $token_source      = Pharma_Hub_Settings::token_source();
        $last_four         = Pharma_Hub_Settings::token_last_four();
        $fixed             = $url_from_constant && 'constant' === $token_source;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Ligação ao hub', 'pharma-hub-plugin' ); ?></h1>
            <?php
            self::render_styles();
            self::render_notices();
            self::render_connection_strip( $state, false );
            ?>
            <div class="pharma-hub-panels">
                <section class="pharma-hub-panel">
                    <h2><?php esc_html_e( 'Endereço e token', 'pharma-hub-plugin' ); ?></h2>
                    <p>
                        <?php
                        echo esc_html(
                            $fixed
                                ? __( 'Definidos no ficheiro wp-config.php, por isso não se alteram aqui.', 'pharma-hub-plugin' )
                                : __( 'Servem para todas as integrações do hub. O token é guardado cifrado e nunca é mostrado de novo.', 'pharma-hub-plugin' )
                        );
                        ?>
                    </p>
                    <?php if ( $fixed ) : ?>
                        <dl>
                            <?php if ( isset( $state['store']['name'] ) ) : ?>
                                <dt><?php esc_html_e( 'Loja', 'pharma-hub-plugin' ); ?></dt>
                                <dd><?php echo esc_html( (string) $state['store']['name'] ); ?></dd>
                            <?php endif; ?>
                            <dt><?php esc_html_e( 'Endereço do hub', 'pharma-hub-plugin' ); ?></dt>
                            <dd><code><?php echo esc_html( $url ); ?></code><span class="hint">PHARMA_HUB_URL</span></dd>
                            <dt><?php esc_html_e( 'Token', 'pharma-hub-plugin' ); ?></dt>
                            <dd>
                                <?php
                                printf(
                                    /* translators: %s: last four characters of the token */
                                    esc_html__( 'Termina em %s', 'pharma-hub-plugin' ),
                                    '<strong>' . esc_html( (string) $last_four ) . '</strong>'
                                );
                                ?>
                                <span class="hint">PHARMA_HUB_TOKEN</span>
                            </dd>
                        </dl>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_CONNECTION ); ?>">
                            <?php wp_nonce_field( self::ACTION_SAVE_CONNECTION ); ?>
                            <div class="field">
                                <label for="pharma_hub_url"><?php esc_html_e( 'Endereço do hub', 'pharma-hub-plugin' ); ?></label>
                                <?php if ( $url_from_constant ) : ?>
                                    <code><?php echo esc_html( $url ); ?></code>
                                    <span class="hint"><?php esc_html_e( 'Definido no wp-config.php (PHARMA_HUB_URL).', 'pharma-hub-plugin' ); ?></span>
                                <?php else : ?>
                                    <input type="url" class="regular-text" id="pharma_hub_url" name="pharma_hub_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://">
                                <?php endif; ?>
                            </div>
                            <div class="field">
                                <label for="pharma_hub_token"><?php esc_html_e( 'Token', 'pharma-hub-plugin' ); ?></label>
                                <?php if ( 'constant' === $token_source ) : ?>
                                    <?php
                                    /* translators: %s: last four characters of the token */
                                    echo esc_html( sprintf( __( 'Definido no wp-config.php (PHARMA_HUB_TOKEN), termina em %s.', 'pharma-hub-plugin' ), (string) $last_four ) );
                                    ?>
                                <?php else : ?>
                                    <input type="password" class="regular-text" id="pharma_hub_token" name="pharma_hub_token" value="" autocomplete="off" spellcheck="false" placeholder="phk_...">
                                    <span class="hint">
                                        <?php
                                        if ( 'database' === $token_source ) {
                                            /* translators: %s: last four characters of the token */
                                            echo esc_html( sprintf( __( 'Guardado, cifrado, termina em %s. Deixe em branco para manter.', 'pharma-hub-plugin' ), (string) $last_four ) );
                                        } elseif ( 'unreadable' === $token_source ) {
                                            esc_html_e( 'Há um token guardado que já não se consegue ler (as chaves do site mudaram). Escreva-o de novo.', 'pharma-hub-plugin' );
                                        } else {
                                            esc_html_e( 'É guardado cifrado. Em alternativa, defina PHARMA_HUB_TOKEN no wp-config.php.', 'pharma-hub-plugin' );
                                        }
                                        ?>
                                    </span>
                                    <?php if ( 'none' !== $token_source ) : ?>
                                        <label class="inline"><input type="checkbox" name="pharma_hub_delete_token" value="1"> <?php esc_html_e( 'Apagar o token guardado', 'pharma-hub-plugin' ); ?></label>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Guardar ligação', 'pharma-hub-plugin' ); ?></button>
                        </form>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * The message shown for a failed call, in the user's terms.
     *
     * @param Pharma_Hub_Error $error The failure.
     * @return string
     */
    public static function error_message( Pharma_Hub_Error $error ) {
        switch ( $error->get_error_code() ) {
            case 'invalid_token':
                $message = __( 'O hub recusou o token. Confirme se está correto e se não foi revogado.', 'pharma-hub-plugin' );
                break;
            case 'insufficient_scope':
                $message = __( 'O token não tem permissão para esta ação.', 'pharma-hub-plugin' );
                break;
            case 'kk_key_rejected':
                $message = __( 'O KuantoKusta não aceitou esta chave. Nada foi alterado.', 'pharma-hub-plugin' );
                break;
            case 'kk_unavailable':
            case 'kk_rate_limited':
                $message = __( 'O KuantoKusta não respondeu. Nada foi alterado; tente de novo dentro de um minuto.', 'pharma-hub-plugin' );
                break;
            case 'too_many_requests':
                $message = __( 'Demasiadas tentativas. Espere alguns minutos antes de tentar de novo.', 'pharma-hub-plugin' );
                break;
            case 'kk_key_missing':
                $message = __( 'A loja ainda não tem a chave da API do KuantoKusta no hub. Configure-a em Definições.', 'pharma-hub-plugin' );
                break;
            case 'kk_settings_missing':
                // Answered only by a hub older than the change that lets the
                // threshold be set before the first collection.
                $message = __( 'O hub só aceita mudar este valor depois da primeira coleta de preços. Peça uma coleta no separador Relatório e tente de novo.', 'pharma-hub-plugin' );
                break;
            case 'plugin_bad_cents':
                $message = __( 'O limite tem de ser um número inteiro de cêntimos. Nada foi alterado.', 'pharma-hub-plugin' );
                break;
            case 'kk_run_too_soon':
                $message = null === $error->get_retry_after()
                    ? __( 'Uma coleta terminou há pouco e o relatório já está atualizado. Espere um pouco antes de pedir outra.', 'pharma-hub-plugin' )
                    : sprintf(
                        /* translators: %s: time, in Portugal time */
                        __( 'Uma coleta terminou há pouco e o relatório já está atualizado. Pode pedir outra a partir das %s.', 'pharma-hub-plugin' ),
                        Pharma_Hub_Format::time( gmdate( 'c', time() + $error->get_retry_after() ), 'H:i' )
                    );
                break;
            case 'kk_run_not_found':
            case 'plugin_bad_run_id':
                $message = __( 'Essa coleta não existe. Recarregue a página.', 'pharma-hub-plugin' );
                break;
            case 'invalid_request':
                $message = __( 'O hub recusou o pedido por ter dados inválidos.', 'pharma-hub-plugin' );
                break;
            case 'plugin_unreachable':
                $message = __( 'Não foi possível contactar o hub. Confirme o endereço.', 'pharma-hub-plugin' );
                break;
            case 'plugin_redirected':
                $message = __( 'O hub respondeu com um redirecionamento, que por segurança não é seguido. Confirme o endereço, incluindo https://.', 'pharma-hub-plugin' );
                break;
            default:
                $message = sprintf(
                    /* translators: %s: error code */
                    __( 'O hub respondeu de forma inesperada (%s).', 'pharma-hub-plugin' ),
                    $error->get_error_code()
                );
        }

        if ( null !== $error->get_request_id() ) {
            $message .= ' ' . sprintf(
                /* translators: %s: request id, for support */
                __( 'Referência: %s', 'pharma-hub-plugin' ),
                $error->get_request_id()
            );
        }
        return $message;
    }

    /**
     * Stops unless the user may manage the store. Each action then checks
     * its own nonce with check_admin_referer().
     *
     * @return void
     */
    public static function check_capability() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Não tem permissão para fazer isto.', 'pharma-hub-plugin' ), 403 );
        }
    }

    /**
     * Keeps notices for the current user and returns to a tab of the page.
     *
     * Notices travel in a short-lived transient rather than in the URL, so a
     * crafted link cannot show a fake message.
     *
     * @param array  $notices List of array( type, message ).
     * @param string $tab     Tab of the KuantoKusta page to return to, or "connection" for that page.
     * @param array  $args    More query arguments, such as the tab's filter.
     * @return void
     */
    public static function redirect_back( $notices, $tab = 'settings', $args = array() ) {
        set_transient( 'pharma_hub_notices_' . get_current_user_id(), $notices, MINUTE_IN_SECONDS );
        wp_safe_redirect( 'connection' === $tab ? self::connection_url() : self::tab_url( $tab, $args ) );
        exit;
    }

    /**
     * Prints and forgets the current user's notices.
     *
     * @return void
     */
    private static function render_notices() {
        $key     = 'pharma_hub_notices_' . get_current_user_id();
        $notices = get_transient( $key );
        if ( ! is_array( $notices ) ) {
            return;
        }
        delete_transient( $key );
        foreach ( $notices as $notice ) {
            $type = 'success' === $notice[0] ? 'notice-success' : 'notice-error';
            printf( '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $type ), esc_html( $notice[1] ) );
        }
    }
}
