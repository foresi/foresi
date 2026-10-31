# Changelog

All notable changes to Foresi are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Foresi adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Generic import and export. Import CSV and XLSX files in the background: encoding, delimiter and header row are detected, columns are mapped with remembered mappings, a dry-run check lists every row error (downloadable as an error file), and three modes are offered (add missing, update existing, full replace, which archives records missing from the file and needs the number of records typed as confirmation). An import can be undone for 30 days. Contacts are the first target, with a preset for the iDoklad contact export. Files up to 50 MB and 200,000 rows; files and staged rows are deleted 30 days after an import ended. Documented in `docs/import.md`.
- Commands `app:import:purge` (deletes old files and staged rows) and `app:import:health` (fails imports that stopped moving and starts the next queued one); run both from cron.
- Contacts have a website and a mobile phone.
- Audit entries carry an optional correlation id that groups the changes of one operation.
- Architecture decision records for the audit correlation id and the asynchronous import pipeline.

### Changed

Upgrading: run the database migrations (`bin/console doctrine:migrations:migrate`); they add the import tables, the audit correlation id, the e-mail comparison key and the contact fields. Start a background worker (`messenger:consume async registry`, one worker on SQLite) and schedule the two import commands; see `docs/import.md`. Set `upload_max_filesize` and `post_max_size` to at least `50M`.

- The address book has import and export actions in its header; the CSV export is now an export menu with CSV and XLSX, and it exports the filtered list.
- Contact roles accept their translated names when imported.
- Background messages run inside the tenant they were sent from; the default `flock` lock works on a single host, use `LOCK_DSN` for several.
- Every response forbids content type sniffing (`X-Content-Type-Options: nosniff`).

## [0.2.1] - 2026-10-30

### Added

- Choice chips component; contact roles are chosen with it.
- Description list component for label and value rows, and an actions slot in the card header; the contact overview uses both.

### Fixed

- The command palette and the user menu appear above the page panel, and the user menu can be wider than the sidebar.
- Organisation and user names in the sidebar wrap to two lines instead of being cut; a user without a name sees the e-mail once.
- The command palette input no longer shows the global focus outline.
- Browser autofill no longer paints form fields blue.

## [0.2.0] - 2026-10-30

### Added

- Design system: Twig components built on Symfony UX and Tailwind design tokens (buttons, inputs, badges, tabs, cards, data table with selection and bulk actions, pagination, KPI strip, facts grid, timeline, empty states and a command palette), with a development component catalogue.
- App shell: sidebar navigation, user menu, active tenant choice and a styled login screen in Czech and English.
- Address book: list with search, views, sorting and paging, detail with overview, contact persons, addresses, bank accounts and history, create and edit forms, archive and restore, bulk archive and CSV export.
- Parties: roles, billing and delivery addresses, persons linked as contact persons, bank accounts and document defaults.
- Registry lookups: Czech business register (ARES) autofill, EU VAT number check (VIES) and the Czech unreliable VAT payer register, with rate limiting.
- End-to-end tests with Playwright covering accessibility (axe), keyboard use and layout on desktop and mobile for the sign-in screen, the address book and the component catalogue. Screenshot comparison specs exist for the sign-in screen and the address book; they run locally against macOS baselines and are not part of CI yet.
- A language preference per user and a language switch in the user menu.
- Architecture decision record for the server-rendered user interface.

### Changed

Upgrading from 0.1.0: run the database migrations (`bin/console doctrine:migrations:migrate`). Several of them drop columns after moving their data, and their `down()` steps cannot restore everything (for example a party with several e-mail addresses), so back up the database first.

- Breaking: the party e-mail column is gone. E-mail addresses live in the new `party_email` table and existing addresses are moved there. `Party::email()` is removed.
- Breaking: the embedded party address (street, city, postal code, country) is gone. It is moved to a billing address in the new `party_address` table. `Party::address()` is removed.
- Breaking: the `is_customer` and `is_supplier` flags are replaced by roles (`Party::roles()`, `hasRole()`, `assignRoles()`). `isCustomer()` and `isSupplier()` are removed.
- Breaking: the `is_primary` column of `party_bank_account` is dropped; the primary account is now stored as a separate flag with a unique index.
- Breaking: the `Party` constructor no longer takes an address or an e-mail address and takes the roles instead. Names, identifiers, phone, roles, note and defaults are changed through methods such as `rename()` and `changeIdentifiers()`.
- Registry results (VIES, unreliable payer and the published bank accounts) belong to a VAT ID: changing or removing it clears them until the next check.
- Search and sorting by name ignore the case of letters with diacritics on SQLite too.

### Fixed

- Logout is a POST request protected by a CSRF token.
- The session is migrated when the active tenant changes.

## [0.1.0] - 2026-10-29

### Added

- Symfony 7.4 application skeleton with Doctrine ORM, Security, Twig, Validator and UID.
- Community files: licence, contributing guide, code of conduct, security policy and contributor licence agreement.
- Architecture decision records for the decision log, the modular monolith, row-level tenancy, money as integer minor units and the append-only audit log.
- Quality gate: PHPStan at maximum level, Deptrac layer rules, Rector, PHP CS Fixer and Infection mutation testing.
- Money: an exact `Money` value object with allocation that never loses or gains a minor unit.
- Tenancy: row-level tenant isolation with a fail-closed Doctrine filter, a tenant context and a scalar-only bypass for cross-tenant lookups.
- Parties: legal entities and parties with registration numbers, VAT IDs and IBANs; Czech registration number validation as the first country module.
- Identity: users, tenant memberships with roles, form login with CSRF protection and throttling, a tenant switch application service and the `app:user:create` command.
- Audit: an append-only audit log for audited entities.
- Tested on SQLite and PostgreSQL 17.
