# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
