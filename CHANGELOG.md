# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-10-09

### Added

- Links between KuantoKusta offers and WooCommerce products, kept in the
  plugin's own table: by SKU, then by EAN (WooCommerce's GTIN field, then
  the EAN plugin's field), then by the product's address on the store. A
  key that finds more than one product links nothing.
- *Vínculos* tab: every offer with its product and how it was linked,
  the offers to resolve first, "Marcar como errado", choosing the product
  with WooCommerce's search, and "Procurar de novo".
- The table is removed when the plugin is deleted.

### Changed

- The page has tabs; the settings moved to *Definições*.
- The EAN field defaults to `_alg_ean` ("EAN Barcode Generator for
  WooCommerce") and is looked up after WooCommerce's own GTIN field
  instead of replacing it.

## [0.1.0] - 2026-10-09

### Added

- Settings screen under WooCommerce → ZincoGroup Hub: the hub's address, the
  token (from `wp-config.php` or stored encrypted), and the meta key of the
  EAN when it does not come from WooCommerce's own GTIN field.
- Connection status: the store's name and whether its KuantoKusta key is
  configured in the hub.
- Write-only field that hands the KuantoKusta key to the hub once; the
  plugin keeps no copy.
- Client of the hub's plugin API: HTTPS only, token in the header, no
  redirects, errors by code.
