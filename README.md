# Foresi

Foresi is an open-source business system for small and medium firms (website: https://foresi.app). It is built as a modular monolith on PHP 8.4, Symfony 7.4 and Doctrine ORM.

## What exists

- **Money**: an exact money value object stored as integer minor units plus a currency code, with allocation that never loses a minor unit.
- **Tenancy**: row-level isolation of tenants, enforced for every ORM and DQL query and failing closed when no tenant is active.
- **Parties**: legal entities and parties with registration numbers, VAT IDs and IBANs. Country rules are plugged in through interfaces; Czech registration number validation is included as a country module.
- **Identity**: users, tenant memberships with roles, form login with CSRF protection and throttling, an application service for switching the active tenant (no user interface for it yet) and a console command to create users.
- **Audit**: an append-only audit log of changes to audited entities.
- **Quality gate**: PHPStan at maximum level, architecture rules checked by Deptrac, Rector, PHP CS Fixer and Infection mutation testing.

Foresi is at an early stage. Interfaces and data structures may change without notice.

## Requirements

- PHP 8.4 or newer with the `ctype`, `iconv`, `intl` and `pdo_sqlite` extensions (`pdo_pgsql` for PostgreSQL)
- Composer 2
- SQLite for development, or PostgreSQL 17 (both are covered by the tests)

## Quick start

```bash
composer install
bin/console about
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

## Architecture

The code base is a modular monolith. See [docs/adr](docs/adr) for the architecture decisions.

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md) first. Report security problems as described in [SECURITY.md](SECURITY.md).

## Licence

Licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only).
