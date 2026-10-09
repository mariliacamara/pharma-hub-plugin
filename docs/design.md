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
  The menu uses the store's `brandName` from `GET /v1/plugin/store`, cached for
  an hour, with "ZincoGroup Hub" as the fallback. When a second store (Farmácia Nova
  Porto) uses the plugin, its plugin list will still say "ZincoGroup Hub"; its screens
  will say its own name.
- Technical: `pharma-hub-plugin` (repository, folder, text domain), `Pharma_Hub_`
  (classes), `pharma_hub_` (options), `PHARMA_HUB_` (constants). Never shown to a client.

## Menu (changed in 0.7.0)

The plugin has its own top-level menu, named after the store's brand, with one entry
per integration and one for what they share:

| Entry | Page slug | What it holds |
|---|---|---|
| KuantoKusta | `pharma-hub` | Tabs *Relatório*, *Vínculos*, *Definições* (easy adjust, KuantoKusta key, EAN field) |
| Ligação ao hub | `pharma-hub-connection` | Hub address and token |

- Why not under WooCommerce any more: the hub will serve more than one integration
  (4DPharma is next). Each one becomes an entry of this menu with its own settings,
  instead of more tabs on one WooCommerce page.
- Why the connection has its own entry: the address and the token belong to the hub,
  not to KuantoKusta. Under *KuantoKusta → Definições* a second integration would have
  to send people to another integration's screen to connect.
- The KuantoKusta page keeps the slug `pharma-hub`, which is also the menu's slug, so
  addresses saved before 0.7.0 still open it.
- A page is recognised by its slug in the request, never by WordPress's hook suffix:
  the suffix of a page under a menu is made from the menu's title, which here is the
  brand name and can change.
- Shared CSS (status strip, cards, pills, tables, panels) is printed by
  `Pharma_Hub_Admin::render_styles()` on both pages.

## Settings (built in 0.1.0)

Each setting has its own form, button and action, so saving one never rewrites
another (0.7.0). When the address and the token both come from `wp-config.php`,
*Ligação ao hub* shows them as information instead of a form that cannot be saved.

| Setting | Where | Notes |
|---|---|---|
| Hub address | `PHARMA_HUB_URL` in `wp-config.php`, or *Ligação ao hub* | https only, no credentials, query or fragment. http and private addresses only when `wp_get_environment_type()` is `local` or `development` |
| Token | `PHARMA_HUB_TOKEN` in `wp-config.php` (recommended), or *Ligação ao hub* | Stored encrypted (libsodium secretbox) with a key derived from `AUTH_KEY` and `AUTH_SALT`. Write-only field; only the last four characters are shown. Not autoloaded |
| KuantoKusta key | *KuantoKusta → Definições* | Write-only. Sent once to `PUT /v1/plugin/kuantokusta/credential`; the hub checks it with KuantoKusta and stores it. The plugin keeps no copy |
| EAN field | *KuantoKusta → Definições* | The meta key of an EAN plugin, looked up after WooCommerce's own GTIN field. Defaults to `_alg_ean` (see "Linking") |

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
those that need a person first. Since 0.7.0:

- A strip at the top answers the only question most visits have: is there anything
  to resolve.
- The counts are cards that filter the list: "Por resolver", "Todas as ofertas" and
  one per way of linking (SKU, EAN, store address, chosen by hand). The old filter
  `links=linked` has no card and still works.
- Search (`s`) by name, REF or EAN, of the offer or of its product. Every word typed
  must be found, in any order; case and accents are ignored. The rule is
  `Pharma_Hub_Linker::matches_search()`, tested without WordPress; accents are
  folded with WordPress's `remove_accents()` before it.
- Actions return to the same list and the same search.
- "Marcar como errado" is a quiet link: with every offer linked, a button on each
  row made the rarest action the loudest thing on the screen.
- The screen does not judge whether a link looks wrong (for instance by comparing
  names): it shows what was linked and how. A person decides.

## Report screen (built in 0.3.0)

The first tab of *ZincoGroup Hub → KuantoKusta*, before *Vínculos* and *Definições*.

- **Data:** every page of `GET /v1/plugin/kuantokusta/report` (200 rows a page; about
  185 for Zincomed), kept for 5 minutes per offer state. "Atualizar" reads the hub
  again. The report's offers are linked on the way, as in *Vínculos*.
- **Header:** when the last collection ended (Portugal time) and how, and a warning
  with the number of `stale` rows.
- **Summary cards (redrawn in 0.6.0):** the hub's counts as a row of cards, each a link
  that shows only its rows (it replaces the outcome and flag filters rather than adding
  to them; "Todas as ofertas" clears them; the active one is filled). The easy-adjust
  card carries the threshold. Order: what can be acted on first.
- **"Diferença suspeita"** (renamed from "Conferir vínculo" in 0.4.0): the difference
  is 50% or more either way. It is shown under that name because the cause is usually
  the KuantoKusta page (another product, variant or pack size), not the link to the
  WooCommerce product. When KuantoKusta moves an offer to the right page the offer keeps
  its reference, so the hub keeps the same offer id and the plugin's link stays.
- **Table (redrawn in 0.6.0), six columns:**
  - product: the linked WooCommerce product's name (without the SKU, which is on the
    line under it as REF), or the offer's name with "sem vínculo"; a link to the
    KuantoKusta page;
  - the store's price;
  - the lowest price, with its store under it; "sem outras lojas" when the store is
    the only one;
  - the difference, the strongest number of the row, signed and coloured (dearer or
    cheaper), with the percentage under it;
  - the position ("30.º de 44") with a marker on a short track, from the cheapest on
    the left to the dearest on the right;
  - the outcome as a pill. "Ajuste fácil" replaces "Mais cara" where it applies; more
    pills for "Diferença suspeita" and "coleta anterior" (stale). An easy-adjust row is
    tinted blue, a "Diferença suspeita" row yellow.
  - No meaning rests on colour alone: differences keep their sign and each pill says
    what it is. Text colours have a contrast of at least 4.5:1.
- **Price to be the cheapest (0.6.0):** on an easy adjust, the lowest price of the
  others minus one cent, as a hint. It is arithmetic on two numbers the hub sent, not a
  comparison: the plugin still computes none. It says nothing about cost or margin.
- **Filters (query string, so a view can be bookmarked):**
  - outcome, only easy adjust, only "Diferença suspeita": through the cards;
  - offer state (active, or including out of stock and delisted);
  - only without a link;
  - "Comparar com portes".
- **Sorting:** the default (0.6.0) puts what can be acted on first: easy adjusts, then
  dearer, tied, cheapest, only store, no data; inside each group the smallest gap
  first. A column title sorts by product, the store's price or the difference, and a
  link brings the default back. Rows without a value always go last.
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

## Regenerate (built in 0.4.0)

The *Regenerar* button sits under the report's header.

1. Pressing it posts to admin-ajax (`pharma_hub_run_start`, capability and nonce). PHP
   calls `POST /v1/plugin/kuantokusta/runs` with the WordPress login as `requestedBy`
   (one line, at most 120 characters). If a collection is already waiting or running,
   the hub returns that one and the page follows it.
2. Every 10 seconds the page asks `pharma_hub_run_status`, which calls
   `GET /runs/{id}` (the id must be a UUID; anything else never reaches the hub). The
   line next to the button says "Coleta pedida; está na fila", "A recolher preços: 40
   de 185 páginas" and so on.
3. When the collection ends, however it ended, the cached report is dropped and the
   page reloads; the header then says how it ended.

- Opening the report while a collection runs (asked by anyone, or by the daily
  schedule) shows its progress at once and keeps the button disabled:
  `GET /runs/latest` is read on every view of the report.
- 429 `kk_run_too_soon` says from what time a new collection can be asked, from
  `Retry-After`, in Portugal time. 409 `kk_key_missing` points to *Definições*.
- Three failed status checks in a row stop the following and say so.
- The browser only talks to admin-ajax; the token stays in PHP.
- The description under the button reminds that a price the store just changed only
  shows after KuantoKusta re-imports the catalogue, usually at night.

## Easy-adjust threshold (built in 0.5.0)

*Definições* shows the store's "easy adjust" threshold and changes it.

- The value lives in the hub (`GET`/`PUT /v1/plugin/kuantokusta/settings/easy-adjust`);
  the plugin keeps no copy. Changing it needs a token with `prices:refresh`.
- It is typed in whole cents, digits only. "0,10" or "10 cêntimos" is refused instead
  of guessed: a value read the wrong way would mark the wrong offers.
- After a change the cached report is forgotten, because it carries the marks made
  with the old threshold.
- The hub accepts a change before the store's first collection (hub pull request #12).
  An older hub answers 409 `kk_settings_missing`; the screen then says to ask for a
  collection first.

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
