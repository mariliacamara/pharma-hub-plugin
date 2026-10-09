<?php
/**
 * The "Regenerar" button: asks the hub for a new collection of competitor
 * prices and follows it from the report screen.
 *
 * The hub answers at once and collects in the background, one product page
 * every few seconds, so a collection takes minutes. The page asks the hub
 * how far it is every 10 seconds, through admin-ajax, and reloads the report
 * when it ends. The browser never talks to the hub: every call goes through
 * PHP, with the token.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The regenerate panel and its admin-ajax actions.
 */
class Pharma_Hub_Admin_Runs {

    const ACTION_START  = 'pharma_hub_run_start';
    const ACTION_STATUS = 'pharma_hub_run_status';
    const NONCE         = 'pharma_hub_runs';
    const SCRIPT        = 'pharma-hub-regenerate';

    /**
     * Statuses of a collection that has not ended yet.
     */
    const ACTIVE = array( 'queued', 'running' );

    /**
     * Hooks the admin-ajax actions.
     *
     * @return void
     */
    public static function register() {
        add_action( 'wp_ajax_' . self::ACTION_START, array( __CLASS__, 'ajax_start' ) );
        add_action( 'wp_ajax_' . self::ACTION_STATUS, array( __CLASS__, 'ajax_status' ) );
    }

    /**
     * Loads the script on the report tab.
     *
     * @return void
     */
    public static function enqueue() {
        wp_enqueue_script(
            self::SCRIPT,
            plugins_url( 'assets/js/regenerate.js', PHARMA_HUB_PLUGIN_FILE ),
            array(),
            PHARMA_HUB_PLUGIN_VERSION,
            true
        );
        wp_localize_script(
            self::SCRIPT,
            'pharmaHubRuns',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'nonce'        => wp_create_nonce( self::NONCE ),
                'startAction'  => self::ACTION_START,
                'statusAction' => self::ACTION_STATUS,
                'intervalMs'   => 10000,
                'failed'       => __( 'Não foi possível saber como está a coleta. Recarregue a página para tentar de novo.', 'pharma-hub-plugin' ),
            )
        );
    }

    /**
     * The button and the line that says how the collection is going.
     *
     * @param Pharma_Hub_Client $client Hub client.
     * @return void
     */
    public static function render_panel( Pharma_Hub_Client $client ) {
        $active = null;
        try {
            $latest = $client->get_latest_run();
            if ( isset( $latest['run']['status'] ) && in_array( $latest['run']['status'], self::ACTIVE, true ) ) {
                $active = $latest['run'];
            }
        } catch ( Pharma_Hub_Error $error ) {
            // The report still shows; the button reports any problem when pressed.
            $active = null;
        }
        ?>
        <div id="pharma-hub-run" class="pharma-hub-run" data-run-id="<?php echo esc_attr( $active ? $active['id'] : '' ); ?>" style="margin: 12px 0">
            <button type="button" class="button button-primary" id="pharma-hub-regenerate" <?php disabled( null !== $active ); ?>><?php esc_html_e( 'Regenerar', 'pharma-hub-plugin' ); ?></button>
            <span class="pharma-hub-run-status" role="status" aria-live="polite" style="margin-left: 8px"><?php echo esc_html( $active ? self::status_message( $active ) : '' ); ?></span>
            <p class="description"><?php esc_html_e( 'Lê de novo os preços das outras lojas no KuantoKusta. Demora alguns minutos. Um preço que a loja acabou de mudar só aparece depois de o KuantoKusta reimportar o catálogo, normalmente de madrugada.', 'pharma-hub-plugin' ); ?></p>
        </div>
        <?php
    }

    /**
     * Asks the hub for a collection.
     *
     * @return void
     */
    public static function ajax_start() {
        self::check_ajax();
        $client = self::client_or_fail();

        try {
            $answer = $client->start_run( wp_get_current_user()->user_login );
        } catch ( Pharma_Hub_Error $error ) {
            wp_send_json_error( array( 'message' => Pharma_Hub_Admin::error_message( $error ) ) );
        }

        $run = isset( $answer['run'] ) ? $answer['run'] : array();
        wp_send_json_success(
            array(
                'runId'   => isset( $run['id'] ) ? (string) $run['id'] : '',
                'message' => empty( $answer['created'] )
                    ? __( 'Já havia uma coleta em curso; a acompanhar essa.', 'pharma-hub-plugin' ) . ' ' . self::status_message( $run )
                    : self::status_message( $run ),
            )
        );
    }

    /**
     * Tells the page how far a collection is.
     *
     * @return void
     */
    public static function ajax_status() {
        self::check_ajax();
        $client = self::client_or_fail();
        // check_ajax() verified the nonce.
        $run_id = isset( $_POST['runId'] ) ? sanitize_text_field( wp_unslash( $_POST['runId'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

        try {
            $answer = $client->get_run( $run_id );
        } catch ( Pharma_Hub_Error $error ) {
            wp_send_json_error( array( 'message' => Pharma_Hub_Admin::error_message( $error ) ) );
        }

        $run      = isset( $answer['run'] ) ? $answer['run'] : array();
        $finished = ! isset( $run['status'] ) || ! in_array( $run['status'], self::ACTIVE, true );
        if ( $finished ) {
            // The next view of the report reads the new comparisons.
            Pharma_Hub_Report::forget();
        }
        wp_send_json_success(
            array(
                'finished' => $finished,
                'message'  => self::status_message( $run ),
            )
        );
    }

    /**
     * How a collection is going, in words.
     *
     * @param array $run Collection from the hub.
     * @return string
     */
    public static function status_message( $run ) {
        $status = isset( $run['status'] ) ? $run['status'] : '';
        $total  = isset( $run['progress']['total'] ) ? (int) $run['progress']['total'] : 0;
        $done   = ( isset( $run['progress']['read'] ) ? (int) $run['progress']['read'] : 0 )
            + ( isset( $run['progress']['notRead'] ) ? (int) $run['progress']['notRead'] : 0 );

        switch ( $status ) {
            case 'queued':
                return __( 'Coleta pedida; está na fila.', 'pharma-hub-plugin' );
            case 'running':
                return $total > 0
                    /* translators: 1: pages done, 2: pages to read */
                    ? sprintf( __( 'A recolher preços: %1$d de %2$d páginas.', 'pharma-hub-plugin' ), min( $done, $total ), $total )
                    : __( 'A preparar a coleta…', 'pharma-hub-plugin' );
            default:
                return __( 'Coleta terminada. A atualizar o relatório…', 'pharma-hub-plugin' );
        }
    }

    /**
     * Stops unless the user may manage the store and the nonce is valid.
     *
     * @return void
     */
    private static function check_ajax() {
        if ( ! current_user_can( Pharma_Hub_Admin::CAPABILITY ) ) {
            wp_send_json_error( array( 'message' => __( 'Não tem permissão para fazer isto.', 'pharma-hub-plugin' ) ), 403 );
        }
        check_ajax_referer( self::NONCE );
    }

    /**
     * The hub client, or an error answer when the plugin is not connected.
     *
     * @return Pharma_Hub_Client
     */
    private static function client_or_fail() {
        $client = Pharma_Hub_Settings::client();
        if ( null === $client ) {
            wp_send_json_error( array( 'message' => __( 'Configure primeiro o endereço do hub e o token.', 'pharma-hub-plugin' ) ) );
        }
        return $client;
    }
}
