# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.7.0] - 2026-10-09

### Changed

- The plugin has its own menu in the sidebar, named after the store's brand
  ("ZincoGroup Hub"), instead of an entry under WooCommerce. Inside it:
  - **KuantoKusta**: the report, the links and KuantoKusta's settings;
  - **Ligação ao hub**: the hub's address and the token, which every
    integration shares.
  A second integration becomes another entry of the same menu, with its own
  settings. The address of the KuantoKusta page did not change.
- *Definições* is a status strip and one panel per setting (easy adjust,
  KuantoKusta key, EAN field), each with its own button, instead of one long
  form with a single "Guardar". Saving one no longer touches the others.
- The address and the token moved to *Ligação ao hub*. When both come from
  `wp-config.php` they are shown as information, not as disabled fields.
- *Vínculos* was redrawn like the report:
  - a strip says at once whether anything needs a person;
  - the counts are cards that filter the list, one per way of linking;
  - "Marcar como errado" is a quiet link instead of a button on every row;
  - how an offer was linked is a pill;
  - the product shows its name with the REF under it;
  - offers without a product are tinted.

### Added

- Search in *Vínculos* by name, REF or EAN, of the offer or of its product,
  ignoring case and accents.

## [0.6.0] - 2026-10-09

### Changed

- The report screen was redrawn so the eye goes to what can be acted on:
  - six columns instead of eight: the lowest price carries its store under
    it, and the difference carries the percentage;
  - the difference is the strongest number of the row, coloured and signed
    (dearer or cheaper);
  - the outcome is a coloured pill, and "Ajuste fácil" takes the place of
    "Mais cara" on the rows it applies to;
  - the default order is no longer alphabetical: easy adjusts first, then
    from the smallest to the largest difference. A column title still sorts,
    and "Voltar à ordem recomendada" brings the default back;
  - the summary is a row of cards that filter the table, replacing the
    outcome list and the "só ajuste fácil" / "só diferença suspeita" boxes;
  - a small marker shows where the store stands among the stores of the page;
  - the product's name no longer repeats the REF shown under it;
  - an offer that only the store sells says "sem outras lojas" instead of
    leaving cells empty;
  - no zebra stripes; the table scrolls sideways on a narrow screen.

### Added

- On an easy adjust, the price that would make the store the cheapest (one
  cent under the lowest price of the others), as a hint. The plugin knows
  nothing about cost or margin; the decision stays with the store.

## [0.5.0] - 2026-10-09

### Added

- *Ajuste fácil* section in *Definições*: shows the store's "easy adjust"
  threshold and changes it, in whole cents. The value lives in the hub; the
  report uses the new one at once. It needs a token with `prices:refresh`.
  With a hub older than the change that accepts it before a first
  collection, the screen says to ask for a collection first.

## [0.4.1] - 2026-10-09

### Fixed

- The summary links above the report ("Mais barata", "Ajuste fácil"…)
  added to the filter already chosen, so a second click could leave the
  table empty (no offer is both the cheapest and an easy adjust). Each
  link now shows only its own rows, the active one is highlighted, and
  "Todas" shows every offer again. The offer state, the shipping switch
  and the order are kept.

## [0.4.0] - 2026-10-09

### Added

- *Regenerar* button on the report: asks the hub for a new collection of
  competitor prices, shows its progress ("A recolher preços: 40 de 185
  páginas") and reloads the report when it ends. A collection already
  running when the page opens is followed at once. When a collection
  ended a moment ago, it says from what time a new one can be asked.

### Changed

- "Conferir vínculo" is now "Diferença suspeita", with an explanation
  that the cause is usually the KuantoKusta page, not the product link.

## [0.3.0] - 2026-10-09

### Added

- *Relatório* tab, the first one: for each offer, the linked product, the
  store's price, the lowest price on KuantoKusta and its store, the
  difference in euros and percent and the position. Easy adjust and
  "check the link" are highlighted; prices from an older collection are
  marked. The header says when the last collection ended and how.
- Filters by outcome, offer state, easy adjust, link check and missing
  link, and a switch to compare with shipping; sorting by product, price,
  difference or percentage.
- CSV export of the filtered report for Excel in Portugal, protected
  against formulas in product names.

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
