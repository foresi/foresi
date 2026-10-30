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

Screenshot specs carry `@visual` in their title. A screen is captured as the viewport (the shell as a user sees it) and, for long pages, a second image of the main content only. The UI is shown in the default language (Czech). Candidates are written to `tests/e2e/screenshots/<project>-<platform>/` (for example `desktop-darwin`, `mobile-linux`); the platform is part of the folder name, so macOS images for review never collide with the Linux baselines, which are generated separately on Linux (CI). A screen's baseline image is committed only after its look is approved; until then a run writes the candidates and reports "missing snapshot". After approval, run `npx playwright test --update-snapshots` and commit the images.

## Architecture

The code base is a modular monolith. See [docs/adr](docs/adr) for the architecture decisions.

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md) first. Report security problems as described in [SECURITY.md](SECURITY.md).

## Licence

Licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only).
