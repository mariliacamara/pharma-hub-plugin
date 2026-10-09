<?php
/**
 * The plugin's settings: the hub's address, the token and the EAN field.
 *
 * Constants in wp-config.php win over the values saved in the settings
 * screen:
 *
 *     define( 'PHARMA_HUB_URL', 'https://hub.example.com' );
 *     define( 'PHARMA_HUB_TOKEN', 'phk_...' );
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Reads, validates and stores the settings.
 */
class Pharma_Hub_Settings {

    const OPTION_URL          = 'pharma_hub_url';
    const OPTION_TOKEN        = 'pharma_hub_token';
    const OPTION_EAN_META_KEY = 'pharma_hub_ean_meta_key';
    const TRANSIENT_STORE     = 'pharma_hub_store';

    /**
     * Shown until the hub has told the plugin the store's own name to show.
     */
    const DEFAULT_BRAND_NAME = 'ZincoGroup Hub';

    /**
     * The shape of a plugin token: phk_ and 32 random bytes in base64url.
     */
    const TOKEN_PATTERN = '/^phk_[A-Za-z0-9_-]{43}$/';

    /**
     * A post meta key: what WooCommerce and other plugins use.
     */
    const META_KEY_PATTERN = '/^[A-Za-z0-9_\-]{1,191}$/';

    /**
     * Checks and normalises the hub's address.
     *
     * Only https is accepted, without credentials, query or fragment. Plain
     * http is accepted only when $allow_local is true, for a hub running on
     * a developer machine.
     *
     * @param string $url         Address typed by the user.
     * @param bool   $allow_local Accept http.
     * @return string|null The address without a trailing slash, or null when it is not acceptable.
     */
    public static function normalize_url( $url, $allow_local = false ) {
        $url   = trim( (string) $url );
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return null;
        }

        $scheme = strtolower( $parts['scheme'] );
        if ( 'https' !== $scheme && ! ( 'http' === $scheme && $allow_local ) ) {
            return null;
        }
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
            return null;
        }

        return rtrim( $url, '/' );
    }

    /**
     * Whether the token has the shape of a plugin token.
     *
     * @param string $token Value typed by the user.
     * @return bool
     */
    public static function is_valid_token( $token ) {
        return is_string( $token ) && 1 === preg_match( self::TOKEN_PATTERN, $token );
    }

    /**
     * Whether a meta key is acceptable for the EAN field. Empty means "use
     * WooCommerce's own GTIN field".
     *
     * @param string $key Meta key typed by the user.
     * @return bool
     */
    public static function is_valid_meta_key( $key ) {
        return '' === $key || 1 === preg_match( self::META_KEY_PATTERN, $key );
    }

    /**
     * Whether this site may talk to a hub over http or on a private address:
     * only on a local or development installation.
     *
     * @return bool
     */
    public static function allow_local() {
        return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
    }

    /**
     * Whether the address is fixed in wp-config.php.
     *
     * @return bool
     */
    public static function url_from_constant() {
        return defined( 'PHARMA_HUB_URL' ) && is_string( PHARMA_HUB_URL ) && '' !== PHARMA_HUB_URL;
    }

    /**
     * The hub's address, or null when it is not set or not acceptable.
     *
     * @return string|null
     */
    public static function url() {
        $url = self::url_from_constant() ? PHARMA_HUB_URL : get_option( self::OPTION_URL, '' );

        return '' === $url ? null : self::normalize_url( $url, self::allow_local() );
    }

    /**
     * Saves the hub's address typed in the settings screen.
     *
     * @param string $url Address typed by the user; empty clears it.
     * @return bool False when the address is not acceptable; nothing is saved then.
     */
    public static function save_url( $url ) {
        if ( '' === trim( (string) $url ) ) {
            delete_option( self::OPTION_URL );
            return true;
        }
        $normalized = self::normalize_url( $url, self::allow_local() );
        if ( null === $normalized ) {
            return false;
        }
        update_option( self::OPTION_URL, $normalized, false );
        self::forget_store();
        return true;
    }

    /**
     * Where the token comes from: constant, database, unreadable or none.
     *
     * "unreadable" means a token is saved but cannot be decrypted any more,
     * usually because the site's secret keys in wp-config.php changed.
     *
     * @return string
     */
    public static function token_source() {
        if ( defined( 'PHARMA_HUB_TOKEN' ) && is_string( PHARMA_HUB_TOKEN ) && '' !== PHARMA_HUB_TOKEN ) {
            return 'constant';
        }
        $sealed = get_option( self::OPTION_TOKEN, '' );
        if ( '' === $sealed ) {
            return 'none';
        }
        return null === Pharma_Hub_Secret_Box::for_site()->open( $sealed ) ? 'unreadable' : 'database';
    }

    /**
     * The token, or null. Only for the HTTP client: never print it.
     *
     * @return string|null
     */
    public static function token() {
        if ( 'constant' === self::token_source() ) {
            return PHARMA_HUB_TOKEN;
        }
        $sealed = get_option( self::OPTION_TOKEN, '' );
        return '' === $sealed ? null : Pharma_Hub_Secret_Box::for_site()->open( $sealed );
    }

    /**
     * The last four characters of the token, to recognise it on screen.
     *
     * @return string|null
     */
    public static function token_last_four() {
        $token = self::token();
        return null === $token ? null : substr( $token, -4 );
    }

    /**
     * Encrypts and saves a token typed in the settings screen.
     *
     * @param string $token The token.
     * @return bool False when it does not have the shape of a token; nothing is saved then.
     */
    public static function save_token( $token ) {
        $token = trim( (string) $token );
        if ( ! self::is_valid_token( $token ) ) {
            return false;
        }
        // Not autoloaded: it is needed only when the hub is called.
        update_option( self::OPTION_TOKEN, Pharma_Hub_Secret_Box::for_site()->seal( $token ), false );
        self::forget_store();
        return true;
    }

    /**
     * Removes the token saved in the database.
     *
     * @return void
     */
    public static function delete_token() {
        delete_option( self::OPTION_TOKEN );
        self::forget_store();
    }

    /**
     * The meta key that holds the EAN, or an empty string for WooCommerce's
     * own GTIN field.
     *
     * @return string
     */
    public static function ean_meta_key() {
        $key = (string) get_option( self::OPTION_EAN_META_KEY, '' );
        return self::is_valid_meta_key( $key ) ? $key : '';
    }

    /**
     * Saves the meta key that holds the EAN.
     *
     * @param string $key Meta key; empty means WooCommerce's own GTIN field.
     * @return bool False when the key is not acceptable; nothing is saved then.
     */
    public static function save_ean_meta_key( $key ) {
        $key = trim( (string) $key );
        if ( ! self::is_valid_meta_key( $key ) ) {
            return false;
        }
        update_option( self::OPTION_EAN_META_KEY, $key );
        return true;
    }

    /**
     * A client for the hub, or null when the address or the token is missing.
     *
     * @return Pharma_Hub_Client|null
     */
    public static function client() {
        $url   = self::url();
        $token = self::token();
        if ( null === $url || null === $token ) {
            return null;
        }
        return new Pharma_Hub_Client( $url, $token, null, self::allow_local() );
    }

    /**
     * The name to show for the hub, as the hub calls it for this store.
     *
     * Read from a cache of one hour, so screens never wait for the hub to
     * show a menu title.
     *
     * @return string
     */
    public static function brand_name() {
        $store = get_transient( self::TRANSIENT_STORE );
        if ( is_array( $store ) && isset( $store['brandName'] ) && is_string( $store['brandName'] ) && '' !== $store['brandName'] ) {
            return $store['brandName'];
        }
        return self::DEFAULT_BRAND_NAME;
    }

    /**
     * Keeps the store's names after the hub returned them.
     *
     * @param array $store Answer of GET /v1/plugin/store.
     * @return void
     */
    public static function remember_store( $store ) {
        if ( isset( $store['name'], $store['brandName'] ) && is_string( $store['name'] ) && is_string( $store['brandName'] ) ) {
            set_transient(
                self::TRANSIENT_STORE,
                array(
                    'name'      => $store['name'],
                    'brandName' => $store['brandName'],
                ),
                HOUR_IN_SECONDS
            );
        }
    }

    /**
     * Forgets the cached store names, after the address or the token changed.
     *
     * @return void
     */
    public static function forget_store() {
        delete_transient( self::TRANSIENT_STORE );
    }
}
