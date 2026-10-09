<?php
/**
 * Encryption of the hub token when it is kept in the database.
 *
 * The recommended place for the token is the PHARMA_HUB_TOKEN constant in
 * wp-config.php. When a store cannot edit that file, the token is stored in
 * the options table encrypted with libsodium (XSalsa20-Poly1305), under a key
 * derived from the site's secret keys in wp-config.php. A copy of the
 * database alone is then not enough to use the token.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Seals and opens short secrets with a key that never touches the database.
 */
class Pharma_Hub_Secret_Box {

    /**
     * Marks the format, so a later change of algorithm can tell old values apart.
     */
    const PREFIX = 'v1:';

    /**
     * Raw 32-byte key.
     *
     * @var string
     */
    private $key;

    /**
     * Builds a box from raw key material, which is hashed to the key size.
     *
     * @param string $material Secret material, such as the site's auth key and salt.
     */
    public function __construct( $material ) {
        $this->key = sodium_crypto_generichash( 'pharma-hub-plugin/token|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
    }

    /**
     * The box for this site: keyed by AUTH_KEY and AUTH_SALT from wp-config.php.
     *
     * @return Pharma_Hub_Secret_Box
     */
    public static function for_site() {
        return new self( wp_salt( 'auth' ) );
    }

    /**
     * Encrypts a secret.
     *
     * @param string $plaintext The secret.
     * @return string Printable sealed value, safe to store as an option.
     */
    public function seal( $plaintext ) {
        $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

        return self::PREFIX . sodium_bin2base64( $nonce . sodium_crypto_secretbox( (string) $plaintext, $nonce, $this->key ), SODIUM_BASE64_VARIANT_ORIGINAL );
    }

    /**
     * Decrypts a value made by seal().
     *
     * Returns null when the value was changed, has another format, or was
     * sealed with other keys (for example after the site's salts were
     * regenerated); the token then has to be saved again.
     *
     * @param string $sealed Value returned by seal().
     * @return string|null
     */
    public function open( $sealed ) {
        if ( ! is_string( $sealed ) || 0 !== strpos( $sealed, self::PREFIX ) ) {
            return null;
        }

        try {
            $raw = sodium_base642bin( substr( $sealed, strlen( self::PREFIX ) ), SODIUM_BASE64_VARIANT_ORIGINAL );
        } catch ( SodiumException $e ) {
            return null;
        }
        if ( strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
            return null;
        }

        $nonce     = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
        $plaintext = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $this->key );

        return false === $plaintext ? null : $plaintext;
    }
}
