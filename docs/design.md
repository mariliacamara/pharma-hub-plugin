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
| EAN field | Settings screen | Empty: WooCommerce's own GTIN field (WooCommerce 9.2+). Otherwise the meta key another plugin uses. Added because where Zincomed keeps the EAN was not confirmed |

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

## Linking offers to products (next)

A table of the plugin, `{prefix}pharma_hub_offer_links`:

| Column | |
|---|---|
| `offer_id` | The hub's `offerId`. Primary key: it survives changes of the KuantoKusta reference |
| `offer_ref` | KuantoKusta's reference, for display |
| `product_id` | The WooCommerce product or variation |
| `method` | `sku`, `ean`, `url` or `manual` |
| `status` | `auto`, `confirmed` or `rejected` |
| `linked_at`, `linked_by` | When and who, for `manual` and `rejected` |

Keys, in this order (Zincomed, 347 offers: 237 by SKU, 77 by EAN, 33 only by name):

1. **SKU**: `wc_get_product_id_by_sku( $offer['sku'] )`.
2. **EAN**: `wc_get_product_id_by_global_unique_id()` (WooCommerce 9.2+), or the
   configured meta key.
3. **Store URL**: `url_to_postid( $offer['storeUrl'] )`, only when the URL is on this
   site.

A key that matches more than one product links nothing: the offer is shown as "to
review". A link is computed once, when an offer first appears, and kept. "Mark as
wrong" sets `rejected`; the user can then choose the product with WooCommerce's own
product search. A rejected link is never recomputed automatically.

## Report screen (next)

- One call to `GET /v1/plugin/kuantokusta/report?limit=200` (Zincomed has about 185
  active offers), following `nextCursor` if there are more, cached for a few minutes
  and dropped when a collection ends.
- `WP_List_Table`: product (linked to its edit screen), Zincomed's price, lowest price
  and store, difference in euros and percent, position, last comparison.
- Highlights: easy adjust (`easyAdjust`) and check the link (`checkLink`).
- Filters: outcome, easy adjust, check the link, without link, and the offer state.
- Header: when the last collection ended and how, in Portugal time; a warning when
  rows are `stale`.
- Prices arrive as integer cents and are only divided by 100 to display (`15,99 €`).
- With or without shipping by default: not decided yet; the hub returns both.

## Regenerate (next)

`POST /v1/plugin/kuantokusta/runs` with the WordPress login as `requestedBy`, then
`GET /runs/{id}` every 10 seconds from the page, through an admin-ajax action that
checks the capability and a nonce. 429 `kk_run_too_soon` shows when it can be asked
again (from `Retry-After`); 409 `kk_key_missing` points to the settings.

## CSV export (next)

For Excel in Portugal: `;` separator, decimal comma, UTF-8 with BOM. A cell that starts
with `=`, `+`, `-` or `@` gets a leading apostrophe, so a product name cannot run as a
formula.

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
