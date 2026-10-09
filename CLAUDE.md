# Working agreements

## Language

- Always talk to the user in **Portuguese**, even when they write in English.
- Everything committed to the repository is in **English**: commit messages,
  README and other documentation, and code comments.
- User-facing strings are in **European Portuguese** (the client is in
  Portugal: "ficheiro", "transferir", "ecrã"), with the `pharma-hub-plugin`
  text domain.

## Names

- The plugin is shown to the client as **ZincoGroup Hub** (plugin header).
  Screens use the store's `brandName` from the hub, cached, with that name as
  the fallback. Never show "Pharma Hub" to a client; it is only the technical
  name (repository, text domain, code prefixes).

## Rules the plugin keeps

- It shows and links. It never calls KuantoKusta, never stores the
  KuantoKusta key and never computes a comparison: the hub does.
- Calls to the hub are made by PHP, server to server, with the token in the
  `Authorization` header, over HTTPS, without following redirects.
- The token lives in `wp-config.php` (`PHARMA_HUB_TOKEN`) or encrypted in the
  database; it never reaches the browser, a URL or a log.
- Every screen needs `manage_woocommerce`; every action checks a nonce;
  secret fields are write-only.
- Prices from the hub are integer cents; divide by 100 only to display.
- Times from the hub are UTC; show them in Portugal time.

## Commit messages

Every commit message must pass [commitlint](https://commitlint.js.org) with
`@commitlint/config-conventional` (see `commitlint.config.js`). Suggested
scopes: `settings`, `client`, `report`, `links`, `runs`, `export`, `ci`.

```bash
echo "feat(settings): store the token encrypted" \
  | npx --yes -p @commitlint/cli -p @commitlint/config-conventional commitlint
```

## Checks before pushing

- `composer lint` (WordPress coding standards, PHP compatibility, docblocks).
- `composer test` (PHPUnit, no WordPress needed).
- `php -l` on every changed PHP file.

The same checks, plus commitlint on every commit of a pull request, run in
GitHub Actions (`.github/workflows/ci.yml`).

## Workflow

- Work on a branch and open a pull request. The base branch is `production`; never push or merge to it.
- Every decision of note goes into `docs/design.md` in the same change.

## Releases

A pull request that changes the plugin version must bump both the
`Version:` header and `PHARMA_HUB_PLUGIN_VERSION`, and add the matching
`## [X.Y.Z] - YYYY-MM-DD` section to `CHANGELOG.md`. After the merge,
`.github/workflows/release.yml` creates the tag, the GitHub release and the
plugin zip; never create tags by hand.
