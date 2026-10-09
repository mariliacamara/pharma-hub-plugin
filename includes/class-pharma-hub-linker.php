<?php
/**
 * Finds the WooCommerce product of a KuantoKusta offer.
 *
 * Keys are tried in this order, and the first that finds exactly one product
 * wins:
 *
 * 1. SKU: the offer's sku against the products' SKU (REF in Zincomed's admin).
 * 2. EAN: the offer's ean against WooCommerce's own GTIN field, then against
 *    the meta key of an EAN plugin.
 * 3. Store URL: the offer's address on the store, resolved to a product.
 *
 * A key that finds more than one product links nothing: picking one of them
 * would be a guess. The offer is then reported as ambiguous, so a person
 * chooses. With Zincomed's 347 offers, SKU found 237, EAN 77 and only the
 * name 33, so SKU alone is not enough.
 *
 * Pure logic: the lookups are passed in, so it is tested without WordPress.
 *
 * @package Pharma_Hub_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Applies the linking rules to one offer.
 */
class Pharma_Hub_Linker {

    /**
     * Returns the ids of the products whose SKU is the given value.
     *
     * @var callable
     */
    private $by_sku;

    /**
     * Returns the ids of the products whose EAN is the given value.
     *
     * @var callable
     */
    private $by_ean;

    /**
     * Returns the ids of the products at the given store address.
     *
     * @var callable
     */
    private $by_url;

    /**
     * Creates a linker.
     *
     * Each lookup takes one string and returns a list of product ids, with
     * at most two entries: two are enough to know a key is ambiguous.
     *
     * @param callable $by_sku Lookup by SKU.
     * @param callable $by_ean Lookup by EAN.
     * @param callable $by_url Lookup by store address.
     */
    public function __construct( $by_sku, $by_ean, $by_url ) {
        $this->by_sku = $by_sku;
        $this->by_ean = $by_ean;
        $this->by_url = $by_url;
    }

    /**
     * Finds the product of one offer.
     *
     * @param array $offer An offer from the hub: sku, ean and storeUrl, each possibly null.
     * @return array{result: string, product_id: int|null, method: string|null}
     *         result is "linked", "ambiguous" (method says which key found
     *         several products) or "none".
     */
    public function find( $offer ) {
        $keys = array(
            'sku' => array( $this->by_sku, self::clean_sku( isset( $offer['sku'] ) ? $offer['sku'] : null ) ),
            'ean' => array( $this->by_ean, self::clean_ean( isset( $offer['ean'] ) ? $offer['ean'] : null ) ),
            'url' => array( $this->by_url, self::clean_url( isset( $offer['storeUrl'] ) ? $offer['storeUrl'] : null ) ),
        );

        $ambiguous = null;
        foreach ( $keys as $method => $key ) {
            list( $lookup, $value ) = $key;
            if ( null === $value ) {
                continue;
            }
            $ids = array_values( array_unique( array_map( 'intval', (array) call_user_func( $lookup, $value ) ) ) );
            if ( 1 === count( $ids ) ) {
                return array(
                    'result'     => 'linked',
                    'product_id' => $ids[0],
                    'method'     => $method,
                );
            }
            // Several products: remember the first such key, and still try
            // the next ones, which may be precise enough.
            if ( count( $ids ) > 1 && null === $ambiguous ) {
                $ambiguous = $method;
            }
        }

        return array(
            'result'     => null === $ambiguous ? 'none' : 'ambiguous',
            'product_id' => null,
            'method'     => $ambiguous,
        );
    }

    /**
     * The SKU as stored by WooCommerce, or null when there is none.
     *
     * @param mixed $sku Value from the hub.
     * @return string|null
     */
    public static function clean_sku( $sku ) {
        if ( ! is_string( $sku ) ) {
            return null;
        }
        $sku = trim( $sku );
        return '' === $sku ? null : $sku;
    }

    /**
     * The EAN as a string of 8 to 14 digits, or null.
     *
     * Spaces and hyphens are removed. Anything else is not an EAN and is not
     * looked up, so a stray value cannot match an unrelated product.
     *
     * @param mixed $ean Value from the hub.
     * @return string|null
     */
    public static function clean_ean( $ean ) {
        if ( ! is_string( $ean ) && ! is_int( $ean ) ) {
            return null;
        }
        $ean = preg_replace( '/[\s-]+/', '', (string) $ean );
        return 1 === preg_match( '/^\d{8,14}$/', $ean ) ? $ean : null;
    }

    /**
     * The store address, when it is an http(s) address.
     *
     * @param mixed $url Value from the hub.
     * @return string|null
     */
    public static function clean_url( $url ) {
        if ( ! is_string( $url ) ) {
            return null;
        }
        $url = trim( $url );
        return 1 === preg_match( '#^https?://#i', $url ) ? $url : null;
    }
}
