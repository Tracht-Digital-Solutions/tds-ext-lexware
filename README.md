# tds-ext-lexware-pkg

**Lexware billing hub** for the TDS panel — connects the panel's data to Lexware
Office (formerly lexoffice). A build-time-composed extension for the panel
platform (`tds-panel-contract-pkg` + `tds-core-panel-*`).

## Features

- **Customer/project directory** (`lx_customer`, `lx_project`) with per-customer /
  per-project net hourly rate + tax rate.
- **Time → invoice** — link `tds-ext-time-tracker-pkg` entries to a project, then
  export a Lexware invoice (draft or finalized) for a customer/project + date
  range. Aggregates hours into service line items.
- **Contact / lead push** — send directory customers, or leads harvested from
  `tds-ext-contact-tickets-pkg` / `tds-ext-support-tickets-pkg`, to Lexware as contacts
  (deduped, `lexware_contact_id` stored back).
- **Invoice log** + dashboard widget + settings panel (API key / URL / defaults)
  with a connection test.

Admin-only (`lexware:read` / `lexware:write`). Shipped in the **admin** product
target.

## How it connects to other extensions

No hard `dependsOn`: it reads the time-tracker / ticket tables **defensively**
over the shared in-process DB (`Service\SourceGateway` existence-checks each
table), so it composes even when a source extension is absent. Its own data is
`lx_`-prefixed and self-contained.

## Configure

Runtime settings live in the core store (ns `lexware`, admin
`/admin/settings/lexware`, or the Einstellungen panel): `api_key` (secret),
`api_url` (default `https://api.lexware.io/v1`; sandbox
`https://api.lexware-sandbox.de/v1`), `default_hourly_rate`, `default_tax_rate`.
Each falls back to an env var (`LEXWARE_API_KEY` etc.). Create the API key in
**Lexware Office → Einstellungen → öffentliche API**. No key ⇒ push/invoice
routes return 503.

## Develop

```bash
npm install --no-package-lock   # pulls tds-panel-contract from GitHub Packages (needs NPM_TOKEN)
npm run type-check && npm run build
composer install                # resolves tds-panel-contract from its public VCS repo
composer test                   # phpunit: Module RBAC + builder units (DB-free)
```

Enable it: add the manifest to the admin `astro.config.mjs`
(`panelHost({ extensions: [...] })`) and `new LexwareModule()` to
`tds-core-panel-api`'s `Modules::enabled()`.

## Versioning

Semver; the release workflow bumps `package.json` **and** `composer.json` in
lockstep and pushes an annotated tag (the Composer release ref). npm →
GitHub Packages (public); the PHP half is consumed via that git tag.
