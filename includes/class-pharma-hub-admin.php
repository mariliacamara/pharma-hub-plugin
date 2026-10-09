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
 * Menu, the page with its tabs, the settings tab and the actions it posts to.
 */
class Pharma_Hub_Admin {

    const CAPABILITY = 'manage_woocommerce';
    const PAGE       = 'pharma-hub';

    const ACTION_SAVE_CONNECTION = 'pharma_hub_save_connection';
    const ACTION_SEND_KK_KEY     = 'pharma_hub_send_kk_key';

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
        add_action( 'admin_post_' . self::ACTION_SAVE_CONNECTION, array( __CLASS__, 'handle_save_connection' ) );
        add_action( 'admin_post_' . self::ACTION_SEND_KK_KEY, array( __CLASS__, 'handle_send_kk_key' ) );
    }

    /**
     * Adds the page under the WooCommerce menu.
     *
     * @return void
     */
    public static function add_menu() {
        $brand = Pharma_Hub_Settings::brand_name();
        add_submenu_page(
            'woocommerce',
            $brand,
            $brand,
            self::CAPABILITY,
            self::PAGE,
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Loads WooCommerce's product search on the plugin's page.
     *
     * @param string $hook_suffix Current admin page.
     * @return void
     */
    public static function enqueue( $hook_suffix ) {
        if ( 'woocommerce_page_' . self::PAGE !== $hook_suffix ) {
            return;
        }
        wp_enqueue_script( 'wc-enhanced-select' );
        wp_enqueue_style( 'woocommerce_admin_styles' );
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
     * Renders the page: title, tabs, notices and the current tab.
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
            <h1><?php echo esc_html( Pharma_Hub_Settings::brand_name() ); ?></h1>
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
     * Saves the hub's address, the token and the EAN field.
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

        if ( isset( $_POST['pharma_hub_ean_meta_key'] ) ) {
            $key = sanitize_text_field( wp_unslash( $_POST['pharma_hub_ean_meta_key'] ) );
            if ( ! Pharma_Hub_Settings::save_ean_meta_key( $key ) ) {
                $notices[] = array( 'error', __( 'O nome do campo do EAN só pode ter letras, números, _ e -.', 'pharma-hub-plugin' ) );
            }
        }

        if ( ! $notices ) {
            $notices[] = array( 'success', __( 'Definições guardadas.', 'pharma-hub-plugin' ) );
        }
        self::redirect_back( $notices );
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
            self::redirect_back( array( array( 'error', __( 'Configure primeiro o endereço do hub e o token.', 'pharma-hub-plugin' ) ) ) );
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
     * Renders the settings tab.
     *
     * @return void
     */
    private static function render_settings_tab() {
        $client = Pharma_Hub_Settings::client();
        $store  = null;
        $kk     = null;
        $failed = null;
        if ( $client ) {
            try {
                $store = $client->get_store();
                Pharma_Hub_Settings::remember_store( $store );
                $kk = $client->get_credential();
            } catch ( Pharma_Hub_Error $error ) {
                $failed = $error;
            }
        }

        $url_from_constant = Pharma_Hub_Settings::url_from_constant();
        $url               = $url_from_constant ? PHARMA_HUB_URL : get_option( Pharma_Hub_Settings::OPTION_URL, '' );
        $token_source      = Pharma_Hub_Settings::token_source();
        $last_four         = Pharma_Hub_Settings::token_last_four();
        $admin_post        = admin_url( 'admin-post.php' );
        ?>
            <h2><?php esc_html_e( 'Estado', 'pharma-hub-plugin' ); ?></h2>
            <?php if ( null === $client ) : ?>
                <p><?php esc_html_e( 'Ainda não está ligado: falta o endereço do hub ou o token.', 'pharma-hub-plugin' ); ?></p>
            <?php elseif ( $failed ) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html( self::error_message( $failed ) ); ?></p></div>
            <?php else : ?>
                <table class="widefat striped" style="max-width: 640px">
                    <tbody>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Loja', 'pharma-hub-plugin' ); ?></th>
                            <td><?php echo esc_html( isset( $store['name'] ) ? (string) $store['name'] : '' ); ?></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Chave do KuantoKusta', 'pharma-hub-plugin' ); ?></th>
                            <td>
                                <?php
                                if ( ! empty( $kk['configured'] ) ) {
                                    printf(
                                        /* translators: %s: last four characters of the key */
                                        esc_html__( 'Configurada no hub (termina em %s)', 'pharma-hub-plugin' ),
                                        esc_html( isset( $kk['lastFour'] ) ? (string) $kk['lastFour'] : '????' )
                                    );
                                } else {
                                    esc_html_e( 'Ainda não configurada', 'pharma-hub-plugin' );
                                }
                                ?>
                            </td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e( 'Ligação ao hub', 'pharma-hub-plugin' ); ?></h2>
            <form method="post" action="<?php echo esc_url( $admin_post ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE_CONNECTION ); ?>">
                <?php wp_nonce_field( self::ACTION_SAVE_CONNECTION ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pharma_hub_url"><?php esc_html_e( 'Endereço do hub', 'pharma-hub-plugin' ); ?></label></th>
                        <td>
                            <?php if ( $url_from_constant ) : ?>
                                <code><?php echo esc_html( $url ); ?></code>
                                <p class="description"><?php esc_html_e( 'Definido no wp-config.php (PHARMA_HUB_URL).', 'pharma-hub-plugin' ); ?></p>
                            <?php else : ?>
                                <input type="url" class="regular-text" id="pharma_hub_url" name="pharma_hub_url" value="<?php echo esc_attr( $url ); ?>" placeholder="https://">
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pharma_hub_token"><?php esc_html_e( 'Token', 'pharma-hub-plugin' ); ?></label></th>
                        <td>
                            <?php if ( 'constant' === $token_source ) : ?>
                                <p>
                                    <?php
                                    printf(
                                        /* translators: %s: last four characters of the token */
                                        esc_html__( 'Definido no wp-config.php (PHARMA_HUB_TOKEN), termina em %s.', 'pharma-hub-plugin' ),
                                        esc_html( (string) $last_four )
                                    );
                                    ?>
                                </p>
                            <?php else : ?>
                                <input type="password" class="regular-text" id="pharma_hub_token" name="pharma_hub_token" value="" autocomplete="off" spellcheck="false" placeholder="phk_...">
                                <p class="description">
                                    <?php
                                    if ( 'database' === $token_source ) {
                                        printf(
                                            /* translators: %s: last four characters of the token */
                                            esc_html__( 'Guardado, cifrado, termina em %s. Deixe em branco para manter.', 'pharma-hub-plugin' ),
                                            esc_html( (string) $last_four )
                                        );
                                    } elseif ( 'unreadable' === $token_source ) {
                                        esc_html_e( 'Há um token guardado que já não pode ser lido (as chaves secretas do wp-config.php mudaram). Escreva-o de novo.', 'pharma-hub-plugin' );
                                    } else {
                                        esc_html_e( 'O mais seguro é defini-lo no wp-config.php: define( \'PHARMA_HUB_TOKEN\', \'phk_...\' ); Se o guardar aqui, fica cifrado na base de dados.', 'pharma-hub-plugin' );
                                    }
                                    ?>
                                </p>
                                <?php if ( 'none' !== $token_source ) : ?>
                                    <label><input type="checkbox" name="pharma_hub_delete_token" value="1"> <?php esc_html_e( 'Apagar o token guardado', 'pharma-hub-plugin' ); ?></label>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="pharma_hub_ean_meta_key"><?php esc_html_e( 'Campo do EAN', 'pharma-hub-plugin' ); ?></label></th>
                        <td>
                            <input type="text" class="regular-text code" id="pharma_hub_ean_meta_key" name="pharma_hub_ean_meta_key" value="<?php echo esc_attr( Pharma_Hub_Settings::ean_meta_key() ); ?>" spellcheck="false">
                            <p class="description"><?php esc_html_e( 'O EAN é procurado primeiro no campo "GTIN, UPC, EAN ou ISBN" do próprio WooCommerce e depois neste campo, de outro plugin. _alg_ean é o do "EAN Barcode Generator for WooCommerce". Em branco: só o campo do WooCommerce.', 'pharma-hub-plugin' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Guardar', 'pharma-hub-plugin' ) ); ?>
            </form>

            <h2><?php esc_html_e( 'Chave da API do KuantoKusta', 'pharma-hub-plugin' ); ?></h2>
            <p><?php esc_html_e( 'A chave é enviada uma vez para o hub, que a confirma com o KuantoKusta e a guarda cifrada. Este site não guarda nenhuma cópia.', 'pharma-hub-plugin' ); ?></p>
            <form method="post" action="<?php echo esc_url( $admin_post ); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SEND_KK_KEY ); ?>">
                <?php wp_nonce_field( self::ACTION_SEND_KK_KEY ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="pharma_hub_kk_key"><?php esc_html_e( 'Nova chave', 'pharma-hub-plugin' ); ?></label></th>
                        <td><input type="password" class="regular-text" id="pharma_hub_kk_key" name="pharma_hub_kk_key" value="" autocomplete="off" spellcheck="false" <?php disabled( null === $client ); ?>></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Enviar para o hub', 'pharma-hub-plugin' ), 'secondary', 'submit', true, null === $client ? array( 'disabled' => 'disabled' ) : null ); ?>
            </form>
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
     * @param string $tab     Tab to return to.
     * @param array  $args    More query arguments, such as the tab's filter.
     * @return void
     */
    public static function redirect_back( $notices, $tab = 'settings', $args = array() ) {
        set_transient( 'pharma_hub_notices_' . get_current_user_id(), $notices, MINUTE_IN_SECONDS );
        wp_safe_redirect( self::tab_url( $tab, $args ) );
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
