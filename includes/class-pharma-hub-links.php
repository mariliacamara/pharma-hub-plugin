<?php
/**
 * The links between KuantoKusta offers and WooCommerce products.
 *
 * A link is found once and kept in the plugin's own table, instead of being
 * recomputed every day. Each row is keyed by the hub's offer id, which
 * survives a change of the KuantoKusta reference.
 *
 * | status    | meaning                                                    |
 * |-----------|------------------------------------------------------------|
 * | auto      | found by SKU, EAN or store address                         |
 * | confirmed | chosen by a person (method "manual")                       |
 * | rejected  | a person said product_id is wrong; nothing is linked again |
 * |           | automatically until a person chooses a product             |
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stores links and resolves the product of each offer.
 */
class Pharma_Hub_Links {

    /**
     * Schema version, kept in an option; bump it when the table changes.
     */
    const DB_VERSION        = '1';
    const OPTION_DB_VERSION = 'pharma_hub_links_db_version';

    /**
     * The hub's offer ids are positive integers of up to 18 digits.
     */
    const OFFER_ID_PATTERN = Pharma_Hub_Client::OFFER_ID_PATTERN;

    /**
     * The table name, with the site's prefix.
     *
     * @return string
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'pharma_hub_offer_links';
    }

    /**
     * Creates or updates the table when the schema version changed.
     *
     * Called on activation and on every admin load, so an update installed
     * without reactivating the plugin also gets its table.
     *
     * @return void
     */
    public static function install() {
        if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta needs two spaces after PRIMARY KEY and one column per line.
        dbDelta(
            'CREATE TABLE ' . self::table() . ' (
  offer_id varchar(20) NOT NULL,
  product_id bigint(20) unsigned DEFAULT NULL,
  method varchar(10) NOT NULL,
  status varchar(10) NOT NULL,
  updated_at datetime NOT NULL,
  updated_by bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (offer_id),
  KEY product_id (product_id)
) ' . $wpdb->get_charset_collate() . ';'
        );
        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    /**
     * Drops the table. Only from uninstall.php.
     *
     * @return void
     */
    public static function uninstall() {
        global $wpdb;
        $wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
        delete_option( self::OPTION_DB_VERSION );
    }

    /**
     * Whether a value is a hub offer id.
     *
     * @param mixed $offer_id Value to check.
     * @return bool
     */
    public static function is_offer_id( $offer_id ) {
        return is_string( $offer_id ) && 1 === preg_match( self::OFFER_ID_PATTERN, $offer_id );
    }

    /**
     * Resolves the product of each offer, linking new offers on the way.
     *
     * @param array $offers Offers from the hub, each with id, sku, ean and storeUrl.
     * @return array Map of offer id to array( state, product_id, method, status ).
     *               state is linked, ambiguous, none or rejected.
     */
    public static function resolve( $offers ) {
        $stored = self::all();
        $linker = self::linker();
        $result = array();

        foreach ( $offers as $offer ) {
            $offer_id = isset( $offer['id'] ) ? (string) $offer['id'] : '';
            if ( ! self::is_offer_id( $offer_id ) ) {
                continue;
            }

            $link = isset( $stored[ $offer_id ] ) ? $stored[ $offer_id ] : null;
            if ( $link && 'rejected' === $link['status'] ) {
                $result[ $offer_id ] = array(
                    'state'      => 'rejected',
                    'product_id' => (int) $link['product_id'],
                    'method'     => $link['method'],
                    'status'     => 'rejected',
                );
                continue;
            }
            if ( $link && self::product_exists( (int) $link['product_id'] ) ) {
                $result[ $offer_id ] = array(
                    'state'      => 'linked',
                    'product_id' => (int) $link['product_id'],
                    'method'     => $link['method'],
                    'status'     => $link['status'],
                );
                continue;
            }
            if ( $link ) {
                // The product was deleted: look for it again.
                self::delete( $offer_id );
            }

            $found = $linker->find( $offer );
            if ( 'linked' === $found['result'] ) {
                self::save( $offer_id, $found['product_id'], $found['method'], 'auto', null );
            }
            $result[ $offer_id ] = array(
                'state'      => $found['result'],
                'product_id' => $found['product_id'],
                'method'     => $found['method'],
                'status'     => 'linked' === $found['result'] ? 'auto' : null,
            );
        }
        return $result;
    }

    /**
     * Links an offer to the product a person chose.
     *
     * @param string $offer_id   Hub offer id.
     * @param int    $product_id WooCommerce product or variation.
     * @param int    $user_id    Who chose it.
     * @return void
     */
    public static function choose( $offer_id, $product_id, $user_id ) {
        self::save( $offer_id, $product_id, 'manual', 'confirmed', $user_id );
    }

    /**
     * Marks the current link of an offer as wrong.
     *
     * @param string $offer_id Hub offer id.
     * @param int    $user_id  Who said so.
     * @return bool False when the offer has no link.
     */
    public static function reject( $offer_id, $user_id ) {
        $link = self::get( $offer_id );
        if ( ! $link || null === $link['product_id'] ) {
            return false;
        }
        self::save( $offer_id, (int) $link['product_id'], $link['method'], 'rejected', $user_id );
        return true;
    }

    /**
     * Forgets the link of an offer, so it is looked for again automatically.
     *
     * @param string $offer_id Hub offer id.
     * @return void
     */
    public static function delete( $offer_id ) {
        global $wpdb;
        $wpdb->delete( self::table(), array( 'offer_id' => $offer_id ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
    }

    /**
     * The link of one offer, or null.
     *
     * @param string $offer_id Hub offer id.
     * @return array|null
     */
    public static function get( $offer_id ) {
        global $wpdb;
        $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
            $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE offer_id = %s', $offer_id ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed table name.
            ARRAY_A
        );
        return $row ? $row : null;
    }

    /**
     * Every stored link, by offer id.
     *
     * @return array
     */
    public static function all() {
        global $wpdb;
        $rows  = $wpdb->get_results( 'SELECT * FROM ' . self::table(), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- The plugin's own table, fixed name.
        $links = array();
        foreach ( (array) $rows as $row ) {
            $links[ $row['offer_id'] ] = $row;
        }
        return $links;
    }

    /**
     * Inserts or replaces a link.
     *
     * @param string   $offer_id   Hub offer id.
     * @param int      $product_id Product or variation.
     * @param string   $method     sku, ean, url or manual.
     * @param string   $status     auto, confirmed or rejected.
     * @param int|null $user_id    Who did it; null when found automatically.
     * @return void
     */
    private static function save( $offer_id, $product_id, $method, $status, $user_id ) {
        global $wpdb;
        $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- The plugin's own table.
            self::table(),
            array(
                'offer_id'   => $offer_id,
                'product_id' => $product_id,
                'method'     => $method,
                'status'     => $status,
                'updated_at' => current_time( 'mysql', true ),
                'updated_by' => $user_id,
            ),
            array( '%s', '%d', '%s', '%s', '%s', '%d' )
        );
    }

    /**
     * Whether a product or variation still exists outside the trash.
     *
     * @param int $product_id Product id.
     * @return bool
     */
    private static function product_exists( $product_id ) {
        $status = get_post_status( $product_id );
        return false !== $status && 'trash' !== $status
            && in_array( get_post_type( $product_id ), array( 'product', 'product_variation' ), true );
    }

    /**
     * The linker, with lookups against this site's products.
     *
     * @return Pharma_Hub_Linker
     */
    private static function linker() {
        $ean_meta_key = Pharma_Hub_Settings::ean_meta_key();

        return new Pharma_Hub_Linker(
            function ( $sku ) {
                return self::products_with_meta( '_sku', $sku );
            },
            function ( $ean ) use ( $ean_meta_key ) {
                // WooCommerce's own GTIN field (9.2+) first, then the EAN plugin's.
                $ids = self::products_with_meta( '_global_unique_id', $ean );
                if ( ! $ids && '' !== $ean_meta_key ) {
                    $ids = self::products_with_meta( $ean_meta_key, $ean );
                }
                return $ids;
            },
            function ( $url ) {
                return self::products_at_url( $url );
            }
        );
    }

    /**
     * Up to two products or variations, outside the trash, with a meta value.
     *
     * Two are enough to know the value is not unique.
     *
     * @param string $key   Meta key.
     * @param string $value Exact value.
     * @return int[]
     */
    private static function products_with_meta( $key, $value ) {
        global $wpdb;
        $ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Exact lookup that WooCommerce has no function for when the value is not unique.
            $wpdb->prepare(
                "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
                 WHERE p.post_type IN ('product', 'product_variation')
                   AND p.post_status NOT IN ('trash', 'auto-draft')
                   AND m.meta_key = %s AND m.meta_value = %s
                 LIMIT 2",
                $key,
                $value
            )
        );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * The product at a store address, when the address is on this site.
     *
     * @param string $url Product address on the store.
     * @return int[]
     */
    private static function products_at_url( $url ) {
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $home = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( ! $host || ! $home || strtolower( preg_replace( '/^www\./i', '', $host ) ) !== strtolower( preg_replace( '/^www\./i', '', $home ) ) ) {
            return array();
        }
        $post_id = url_to_postid( $url );
        return $post_id && 'product' === get_post_type( $post_id ) ? array( (int) $post_id ) : array();
    }
}
