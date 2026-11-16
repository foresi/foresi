# Foresi

Foresi is an open-source business system for small and medium firms (website: https://foresi.app). It is built as a modular monolith on PHP 8.4, Symfony 7.4 and Doctrine ORM.

## What exists

- **Money**: an exact money value object stored as integer minor units plus a currency code, with allocation that never loses a minor unit.
- **Tenancy**: row-level isolation of tenants, enforced for every ORM and DQL query and failing closed when no tenant is active.
- **Parties**: legal entities and parties with registration numbers, VAT IDs and IBANs. Country rules are plugged in through interfaces; Czech registration number validation is included as a country module.
- **Identity**: users, tenant memberships with roles, form login with CSRF protection and throttling, a choice of the active tenant and a console command to create users.
- **Address book**: customers, suppliers and other parties with roles, addresses, contact persons, bank accounts and document defaults; search, views, bulk archive and export to CSV or XLSX; lookups in the Czech business register (ARES), the EU VAT number check (VIES) and the Czech unreliable VAT payer register.
- **Import and export**: a generic import of CSV and XLSX files (contacts first, with an iDoklad preset) that runs in a background worker: column mapping with remembered mappings, a dry-run check, three modes (add missing, update, full replace with typed confirmation), undo within 30 days; the same field definitions drive the export. See [docs/import.md](docs/import.md).
- **Move from iDoklad**: a firm's iDoklad account read through its API (read-only) into a stored snapshot, checked with a report of what would be refused or differs and the sums by year, then imported: contacts, number series, issued and advance invoices with their original PDFs, payments with tax documents and deductions, and recurring invoices, paused. An import can be undone for 30 days. See [docs/idoklad.md](docs/idoklad.md).
- **Invoices**: invoices with lines, VAT per rate, reverse charge, number series per firm, a trash and history, a PDF/A-3a with ISDOC 6.0.2 attached and a QR payment code, and a download of the PDFs of a period for the accountant. Tax rules are subject to professional tax and legal review. See [docs/invoicing.md](docs/invoicing.md).
- **Projects and tasks**: projects for a client or internal, with sections, a workflow of statuses of your own, tasks with subtasks, a responsible person, watchers, due dates, priorities and labels; a list, a board with drag and drop and a personal list of tasks. See [docs/projects.md](docs/projects.md).
- **Received documents**: supplier invoices, receipts and credit notes in one list beside the original file, as drafts from uploaded PDFs or recorded with an evidence number of the firm's own; VAT treatments (domestic, reverse charge, services from the EU and from outside it) with the VAT per rate, foreign currencies at the ČNB rate or one you type, the deduction dates, warnings, and a trash with restore. See [docs/purchasing.md](docs/purchasing.md).
- **REST API and MCP server**: a read-only API for invoices, contacts and payments with personal API tokens, an OpenAPI document and cursor paging, and an MCP server that lets an assistant read the same data. See [docs/api.md](docs/api.md) and [docs/mcp.md](docs/mcp.md).
- **User interface**: server-rendered with Twig, Symfony UX and Tailwind; a component library with a development catalogue; Czech and English.
- **Audit**: an append-only audit log of changes to audited entities.
- **Quality gate**: PHPStan at maximum level, architecture rules checked by Deptrac, Rector, PHP CS Fixer, Infection mutation testing and Playwright end-to-end tests with accessibility and visual checks.

Foresi is at an early stage. Interfaces and data structures may change without notice.

## Requirements

- PHP 8.4 or newer with the `ctype`, `dom`, `gd`, `iconv`, `intl`, `sodium`, `zip` and `pdo_sqlite` extensions (`pdo_pgsql` for PostgreSQL)
- `max_input_vars` of at least `1000`, the PHP default, so an invoice of 100 lines arrives whole (a form cut off by a lower limit is refused and nothing is saved)
- `upload_max_filesize` and `post_max_size` of at least `50M` in php.ini, so imports can take files up to the 50 MB the application allows (a lower server limit is reported as such on the upload screen)
- Composer 2
- SQLite 3.35 or newer for development, or PostgreSQL 17 (both are covered by the tests)

Owners invite their staff and the people they share work with by e-mail; see [docs/people.md](docs/people.md).

To run Foresi on a server (configuration, zero-downtime deploys, the worker, scheduled jobs, monitoring, backups and restores), see [docs/operations.md](docs/operations.md). PostgreSQL 17 is recommended in production.

## Quick start

```bash
composer install
bin/console about
composer isdoc:schema   # the ISDOC schema for the tests, fetched and checked by SHA-256, never committed
composer qa
```

Styles are built by Tailwind CSS (standalone binary, downloaded by the bundle into `var/tailwind/` on first use; no Node needed). Build them before opening the app in a browser:

```bash
bin/console tailwind:build           # once
bin/console tailwind:build --watch   # while developing
bin/console tailwind:build --minify  # before deploying, then bin/console asset-map:compile
```

For a production build run, in this order and with `APP_ENV=prod`: `bin/console importmap:install`, `bin/console tailwind:build --minify`, `bin/console asset-map:compile`. Missing imports are an error in this build (strict `missing_import_mode`), and CI fails on any warning in the output. Icons are never fetched at runtime; add them with `bin/console ux:icons:import lucide:<name>` and commit them.

The built CSS is not committed, and tests and `composer qa` do not need it. Inter fonts (SIL OFL 1.1) and Lucide icons (ISC) are self-hosted in `assets/`; nothing is loaded from a CDN. All UI text lives in `translations/` (Czech default, English).

The default configuration in `.env` uses a local SQLite database in `var/data.db`. Put local overrides, including `APP_SECRET`, in `.env.local`, which is never committed.

Firm logos and stamps are stored in `<APP_SHARE_DIR>/<environment>/firm-images`, which is `var/share/prod/firm-images` by default, outside the web root. Keep that directory on persistent storage and include it in backups: documents refer to these files.

## Background worker

Long-running work (imports, register re-checks, sending e-mail, timed jobs) runs in a worker, not in the web request. Messages are stored in the database (`messenger_messages`, created by a migration) and every message is handled inside the tenant it was dispatched in. Run migrations, then start one worker; it consumes the schedule's ticks (`scheduler_foresi`) and the outgoing mail (`mail`) first, then the domain events (`events`: their relay and the delivery to listeners), then `async`, then the outbound webhooks (`webhooks`: one attempt per message, after `async` so a slow receiver never holds up imports or timed jobs), and `registry` (low priority) last. An installation with many webhook endpoints also runs a second worker, `messenger:consume webhooks` (see [operations](docs/operations.md)). The first worker:

```bash
php bin/console messenger:consume scheduler_foresi mail events async webhooks registry --memory-limit=256M --time-limit=3600
```

The worker exits after an hour or at the memory limit, so run it under a supervisor that restarts it. With systemd:

```ini
[Service]
WorkingDirectory=/var/www/foresi
ExecStart=/usr/bin/php bin/console messenger:consume scheduler_foresi mail events async webhooks registry --memory-limit=256M --time-limit=3600
Restart=always
RestartSec=5
User=www-data
```

With supervisor, use the same command with `autorestart=true` and `stopwaitsecs=3600`. On SQLite run exactly one worker (SQLite allows a single writer). Failed messages are retried three times (2 s, 8 s, 32 s) and then kept in the `failed` queue: inspect them with `bin/console messenger:failed:show` and retry with `messenger:failed:retry`. A message is redelivered if its handler runs longer than two hours (`redeliver_timeout: 7200`). The `e2e` environment has no worker and handles messages inline. Imports need the worker and two scheduled commands (`app:import:purge`, `app:import:health`); see [docs/import.md](docs/import.md). Sending invoices needs the `mail` and `scheduler_foresi` transports in the worker and `MAIL_SECRET_KEYS`; see [docs/sending.md](docs/sending.md).

## End-to-end tests

Playwright tests in `tests/e2e` drive the real application in a browser: sign-in, the address book, keyboard use, accessibility (axe, WCAG 2.2 AA, zero serious or critical violations) and layout on a desktop (1440 px) and a mobile (390 px) viewport. They run in their own `e2e` environment with a separate SQLite database (`var/e2e.db`, recreated and seeded with example data by `tests/e2e/serve.sh`), and the registers (ARES, VIES, the unreliable payer register) are replaced by the same fixture-backed fakes the PHPUnit suite uses, so nothing calls the network. Your development database is never touched.

```bash
bin/console importmap:install        # Stimulus and Turbo, git-ignored under assets/vendor
bin/console tailwind:build           # styles must be built once
cd tests/e2e
npm ci
npx playwright install chromium
npx playwright test                  # everything, including the screenshot specs
npx playwright test --grep-invert @visual   # what CI runs until the baselines are approved
```

Screenshot specs carry `@visual` in their title. A screen is captured as the viewport (the shell as a user sees it) and, for long pages, a second image of the main content only. The UI is shown in the default language (Czech). Candidates are written to `tests/e2e/screenshots/<project>-<platform>/` (for example `desktop-darwin`, `mobile-linux`); the platform is part of the folder name. Only the Linux baselines are committed and checked; they are generated on Linux by the E2E workflow (`update-snapshots`). macOS images are local review candidates and are ignored by git. A screen's baseline image is committed only after its look is approved; until then a run writes the candidates and reports "missing snapshot". After approval, run `npx playwright test --update-snapshots` and commit the images.

## Architecture

The code base is a modular monolith. See [docs/adr](docs/adr) for the architecture decisions.

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md) first. Report security problems as described in [SECURITY.md](SECURITY.md).

## Licence

Licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only).
