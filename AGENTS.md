# AGENTS.md — tds-ext-lexware-pkg

The **Lexware billing hub** panel extension: connects the panel's data to
Lexware Office (formerly lexoffice). Read `tds-panel-contract-pkg`'s AGENTS.md first
(extensions implement that contract); `tds-ext-support-tickets-pkg` is the deepest
reference for the container-first Module pattern, and this extension ports the
Lexware client/invoice logic originally in `tds-customer-api`.

## What it does

An **admin-only** extension (`lexware:read` / `lexware:write`) with four surfaces
(one hub page, tabs) + a dashboard widget + a settings panel:

1. **Customer/project directory** (`lx_customer`, `lx_project`) — a lightweight
   directory the extension owns so tracked time can be tied to a customer + rate
   before billing. NOT the (future) org-wide customer directory.
2. **Time → invoice export** — aggregates `tds-ext-time-tracker-pkg` `time_entry`
   rows that are linked to a project (`lx_time_link`) into a Lexware invoice
   (`POST /v1/invoices`, draft or `?finalize=true`). Effective net rate:
   request override → project → customer → global default.
3. **Contact / lead push** — pushes a directory customer, or a lead harvested
   from the ticket systems, to Lexware as a contact (`POST /v1/contacts`), with a
   dedupe map (`lx_contact_map`) + the stored `lexware_contact_id`.
4. **Invoice audit log** (`lx_invoice_log`) — one row per export; backs the list
   + the widget count.

## Architecture notes (don't regress)

- **No hard `dependsOn`.** Cross-extension reads (`time_entry`, `contact_message`,
  `ticket`) go through `Service\SourceGateway`, which checks table existence via
  `information_schema` and returns `[]` when a source extension isn't composed —
  so Lexware composes on its own. Shared DB, one in-process PDO.
- **Own tables are `lx_`-prefixed**; the single migration class is `Lexware*`-
  prefixed (in-process auto-migrator = one process = no class-name reuse). No
  cross-domain FK on `lx_time_link.time_entry_id` (another extension owns that
  table — same rule as `ticket.customer_id`). MySQL-8-safe: `signed => false` on
  every id/FK column.
- **Config via the core `SettingsStore` (contract interface), ns=`lexware`** —
  `api_key` (secret), `api_url`, `default_hourly_rate`, `default_tax_rate`, read
  DB-first with an env fallback (`LEXWARE_API_KEY` / `LEXWARE_API_URL` /
  `LEXWARE_DEFAULT_HOURLY_RATE` / `LEXWARE_TAX_RATE_PERCENT`). The settings island
  writes the core `/admin/settings/lexware`; the module resolves
  `SettingsStore::class` from the container (null in isolated tests → env
  fallback). Reads use explicit `getenv(...) === false` checks (the `?? … ?:`
  precedence trap must not clobber a legit `"0"`).
- **`LexwareClient` is plain ext-curl** (no Guzzle) — the extension convention.
  Create endpoints return 201+`id`; German error mapping (401/402/403/404/406 +
  `IssueList[0].i18nKey`). `isConfigured()` false (no key) → routes 503.
- **Builders are pure/stateless** (`LexwareInvoiceBuilder`, `LexwareContactBuilder`)
  → unit-tested without the HTTP client. Invoice `address` uses `contactId` when
  the customer has a Lexware contact, else a free-text `name`.

## Conventions baked in (from the template)

- Depends on the **published** `tds-panel-contract` `^1.0.0` — Composer via the
  public **VCS** repo (no path repo — CI fatals on a missing path repo), npm from
  GitHub Packages (`.npmrc` + `NPM_TOKEN` set from `PACKAGE_TOKEN`).
- CI installs with **`npm install --no-package-lock`** (win32 lockfile breaks the
  Linux runner). Prune steps are `continue-on-error` (needs `delete:packages`).
- Release bumps `package.json` + `composer.json` in lockstep; the pushed
  **annotated** tag is the Composer release ref.

## Commands

```bash
composer install && composer test    # phpunit: Module RBAC + builder units
npm install --no-package-lock && npm run type-check && npm run build
```

DB-backed integration runs against real MariaDB/MySQL only when `TDS_TEST_DB_DSN`
is set (the unit suite is DB-free — auth/validation short-circuits before any
repo). Register `new LexwareModule()` in `tds-core-panel-api`'s `Modules::enabled()`
and add the manifest to the admin target's `panelHost({ extensions })`.
