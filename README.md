# ZincoGroup Hub — WordPress plugin

The WordPress side of the price report. The hub
([`pharma-hub`](https://github.com/mariliacamara/pharma-hub)) reads the store's offers
on KuantoKusta, collects competitor prices and compares them; this plugin shows the
result inside WooCommerce and links each KuantoKusta offer to a WooCommerce product.

The plugin is shown to the client as **ZincoGroup Hub**. "Pharma Hub" is only the
technical name of the service and of this repository.

## Status

| Part | State |
|---|---|
| Settings: hub address, token, KuantoKusta key, EAN field | Built (0.1.0) |
| Client of the hub's API | Built (0.1.0) |
| Linking offers to WooCommerce products | Designed (`docs/design.md`) |
| Report screen, filters, CSV export | Designed |
| "Regenerate" button | Designed |

## Requirements

| | Minimum |
|---|---|
| PHP | 7.4, with the `sodium` extension (bundled since PHP 7.2) |
| WordPress | 6.1 |
| WooCommerce | 8.0. Linking by EAN through WooCommerce's own GTIN field needs 9.2 |
| Hub | A plugin token (`phk_...`) for the store, issued with `npm run cli -- token:issue` |

## Installing

1. Download `pharma-hub-plugin-X.Y.Z.zip` from the
   [releases](https://github.com/mariliacamara/pharma-hub-plugin/releases) and upload it
   in *Plugins → Add New → Upload Plugin*.
2. Recommended: put the hub's address and the token in `wp-config.php`, above the
   "That's all, stop editing!" line:

   ```php
   define( 'PHARMA_HUB_URL', 'https://hub.example.com' );
   define( 'PHARMA_HUB_TOKEN', 'phk_...' );
   ```

   Without them, type both in *WooCommerce → ZincoGroup Hub*. The token is then
   stored encrypted with a key derived from the site's secret keys in
   `wp-config.php`; if those keys are regenerated, the token has to be typed again.
3. In the same screen, send the store's KuantoKusta API key to the hub. The hub
   checks it with KuantoKusta and stores it; the plugin keeps no copy.
4. If the products' EAN does not come from WooCommerce's own "GTIN, UPC, EAN or ISBN"
   field, type the meta key where the other plugin stores it.

The screen needs the `manage_woocommerce` capability (administrators and shop
managers).

## Development

```bash
composer install
composer lint     # WordPress coding standards, PHP compatibility, docblocks
composer test     # PHPUnit; no WordPress needed
```

The tests load the plugin's classes with a handful of stubbed WordPress functions
(`tests/bootstrap.php`). Code that needs a real WordPress is checked on a test site.

Commit messages follow Conventional Commits (`commitlint.config.js`). CI runs the lint,
`php -l` on PHP 7.4 and 8.3, PHPUnit on both, and commitlint on every commit of a pull
request. Merging a version bump into `main` creates the tag, the release and the zip
(`.github/workflows/release.yml`).

## Layout

```
pharma-hub-plugin.php                    plugin header, constants, bootstrap
includes/
  class-pharma-hub-settings.php          settings: read, validate, store
  class-pharma-hub-secret-box.php        encryption of the token kept in the database
  class-pharma-hub-client.php            calls to the hub
  class-pharma-hub-error.php             a failed call, with the hub's error code
  class-pharma-hub-admin.php             menu, settings screen, form actions
uninstall.php                            removes the settings when the plugin is deleted
tests/                                   PHPUnit, without WordPress
docs/design.md                           how the plugin works and why
```
