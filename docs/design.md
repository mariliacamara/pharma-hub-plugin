# Design

How the plugin works and why. Agreed with the owner on 2026-10-09; update this file in
the same change as any decision it records.

## Role

The hub ([`pharma-hub`](https://github.com/mariliacamara/pharma-hub)) holds the
KuantoKusta key, reads KuantoKusta, stores history and computes every comparison. The
plugin:

- shows the report the hub computed;
- links each KuantoKusta offer to a WooCommerce product;
- asks the hub for a new collection ("regenerate") and follows it;
- exports the report as CSV.

It never calls KuantoKusta, never stores the KuantoKusta key and never computes a
comparison. Opening the report costs at most one call to the hub, which answers from
its database; a collection never runs inside a request.

## Names

- Shown to the client: **ZincoGroup Hub**. That is the plugin header, fixed in the file.
  Menus and titles use the store's `brandName` from `GET /v1/plugin/store`, cached for
  an hour, with "ZincoGroup Hub" as the fallback. When a second store (Farmácia Nova
  Porto) uses the plugin, its plugin list will still say "ZincoGroup Hub"; its screens
  will say its own name.
- Technical: `pharma-hub-plugin` (repository, folder, text domain), `Pharma_Hub_`
  (classes), `pharma_hub_` (options), `PHARMA_HUB_` (constants). Never shown to a client.

## Settings (built in 0.1.0)

| Setting | Where | Notes |
|---|---|---|
| Hub address | `PHARMA_HUB_URL` in `wp-config.php`, or the settings screen | https only, no credentials, query or fragment. http and private addresses only when `wp_get_environment_type()` is `local` or `development` |
| Token | `PHARMA_HUB_TOKEN` in `wp-config.php` (recommended), or the settings screen | Stored encrypted (libsodium secretbox) with a key derived from `AUTH_KEY` and `AUTH_SALT`. Write-only field; only the last four characters are shown. Not autoloaded |
| KuantoKusta key | Settings screen | Write-only. Sent once to `PUT /v1/plugin/kuantokusta/credential`; the hub checks it with KuantoKusta and stores it. The plugin keeps no copy |
| EAN field | Settings screen | The meta key of an EAN plugin, looked up after WooCommerce's own GTIN field. Defaults to `_alg_ean` (see "Linking") |

Why the database is allowed for the token at all: some stores cannot edit
`wp-config.php`. Encrypting with the site's secret keys means a copy of the database
alone does not reveal the token. If the keys are regenerated, the token becomes
unreadable and the screen asks for it again.

## Calls to the hub (built in 0.1.0)

- PHP only, `wp_remote_request`, token in the `Authorization` header.
- No redirects (`redirection => 0`): a redirect would carry the token elsewhere.
- `reject_unsafe_urls` on, except on a local or development site.
- 15-second timeout. The hub never runs a collection inside a request.
- Errors are `Pharma_Hub_Error`, with the hub's `code`, the HTTP status, `Retry-After`
  and the hub's `requestId`. Screens branch on the code and show a Portuguese message
  with the request id as a reference. Codes made by the plugin start with `plugin_`.

## Linking offers to products (built in 0.2.0)

What Zincomed's admin shows (2026-10-09): the SKU column is labelled **REF**; the EAN
is in two columns, WooCommerce's own "GTIN, UPC, EAN ou ISBN" (WooCommerce 9.2+, meta
`_global_unique_id`) and "EAN" from the plugin "EAN Barcode Generator for WooCommerce"
(WPFactory, formerly "EAN for WooCommerce", meta `_alg_ean` by default). On the
products checked, both hold the same value.

A table of the plugin, `{prefix}pharma_hub_offer_links`, created on activation and on
any admin load after an update (`Pharma_Hub_Links::install`, versioned by an option):

| Column | |
|---|---|
| `offer_id` | The hub's offer `id`. Primary key: it survives changes of the KuantoKusta reference |
| `product_id` | The WooCommerce product or variation |
| `method` | `sku`, `ean`, `url` or `manual` |
| `status` | `auto`, `confirmed` or `rejected` |
| `updated_at`, `updated_by` | When (UTC) and who; no one for `auto` |

Keys, in this order (Zincomed, 347 offers: 237 by SKU, 77 by EAN, 33 only by name):

1. **SKU**: `_sku` equal to the offer's `sku`, trimmed.
2. **EAN**: 8 to 14 digits (spaces and hyphens removed). `_global_unique_id` first,
   then the configured meta key (`_alg_ean` by default; empty to skip it).
3. **Store URL**: `url_to_postid( $offer['storeUrl'] )`, only when the address is on
   this site's host (with or without `www.`) and resolves to a product. On a staging
   copy the offers point to the live site, so this key finds nothing there.

The lookups query `wp_posts`/`wp_postmeta` directly, for products and variations
outside the trash, and stop at two results: WooCommerce's own lookup functions return
one id and hide a duplicate. A key that finds more than one product links nothing
and the next key is tried; if none is precise, the offer is "ambiguous" and a person
chooses. Pure rules in `Pharma_Hub_Linker`, tested without WordPress.

- A link is found once, when an offer first appears, and kept.
- If the linked product is deleted or trashed, the link is dropped and looked for again.
- "Marcar como errado" sets `rejected`: the product is shown struck through and nothing
  is linked automatically again until a person chooses a product (WooCommerce's own
  product search) or asks to look again.
- Ambiguous and unmatched offers are not stored; they are looked up on every visit.

The *Vínculos* tab lists every offer the hub copied (`GET /offers`, all pages),
those that need a person first, with counts by key and filters "Por resolver",
"Vinculadas", "Todas".

## Report screen (built in 0.3.0)

The first tab of *WooCommerce → ZincoGroup Hub*, before *Vínculos* and *Definições*.

- **Data:** every page of `GET /v1/plugin/kuantokusta/report` (200 rows a page; about
  185 for Zincomed), kept for 5 minutes per offer state. "Atualizar" reads the hub
  again. The report's offers are linked on the way, as in *Vínculos*.
- **Header:** when the last collection ended (Portugal time) and how; a warning with
  the number of `stale` rows; the hub's summary by outcome, easy adjust and link
  check, each a link that filters the table; the thresholds in words.
- **Table:**
  - product (the linked WooCommerce product, or the offer's name with "sem vínculo");
  - REF and a link to the KuantoKusta page;
  - the store's price, the lowest price and its store;
  - the difference in euros and percent, the position;
  - the outcome, with badges for easy adjust (green row), check the link (orange row)
    and "coleta anterior" (stale).
- **Filters (query string, so a view can be bookmarked):**
  - outcome;
  - offer state (active, or including out of stock and delisted);
  - only easy adjust, only check the link, only without a link;
  - "Comparar com portes".
- **Sorting:** by product, the store's price, the difference or the percentage. Rows
  without a value always go last.
- **With shipping:** the table shows the totals the hub stored (`storeTotalCents`,
  `lowestTotalCents`, `totalDifferenceCents`) and hides the percentage and position,
  which the hub computes only without shipping. The plugin computes nothing. Which
  comparison is the default is still open; without shipping until decided.
- **Formatting:** cents are formatted with integer arithmetic (`15,99 €`, thousands
  with a thin space); percentages with one decimal and a sign (`+3,2 %`); times in
  `Europe/Lisbon`.

## CSV export (built in 0.3.0)

"Transferir CSV" exports the rows as filtered and sorted on screen (POST to
`admin-post.php`, capability and nonce), for Excel in Portugal:

- `;` separator, decimal comma, UTF-8 with BOM, CRLF lines;
- amounts without the thousands separator, so a spreadsheet reads them as numbers;
- both comparisons, with and without shipping, in the same file;
- a text cell that starts with `=`, `+`, `-`, `@`, a tab or a carriage return gets a
  leading apostrophe, so a product name cannot run as a formula. Numbers formatted by
  the plugin are exempt, so a negative difference stays a number.

## Regenerate (next)

`POST /v1/plugin/kuantokusta/runs` with the WordPress login as `requestedBy`, then
`GET /runs/{id}` every 10 seconds from the page, through an admin-ajax action that
checks the capability and a nonce. 429 `kk_run_too_soon` shows when it can be asked
again (from `Retry-After`); 409 `kk_key_missing` points to the settings.

## Security

- Every screen and action: `manage_woocommerce`.
- Every action: a nonce (`check_admin_referer`, `check_ajax_referer`).
- Everything from the hub is escaped on output.
- Notices travel in a per-user transient, not in the URL, so a crafted link cannot show
  a fake message.

## Testing

- PHPUnit without WordPress for the pure parts: encryption, validation, the client
  with a fake transport, error messages.
- On a real site: `staging.zincomed.com`, which runs WooCommerce with the WoodMart
  theme.
