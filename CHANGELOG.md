# Changelog

All notable changes to Foresi are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Foresi adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Planned as 0.5.0: the first release with invoices.

### Upgrading

- Run `bin/console doctrine:migrations:migrate`. The new tables hold series, number counters, invoices, their lines and the numbers an edit gave up.
- Install the PHP extensions `gd` (the QR code and images), `dom` (the ISDOC file) and `zip` (the bulk download), which are now required, and run `composer install` to get the new PDF library, `tecnickcom/tc-lib-pdf` (LGPL-3.0-or-later). The PDF fonts (Inter, SIL Open Font License) are part of the repository.
- Images of a firm are kept in the `firm-images` directory of the application's share directory (`var/share/<env>/firm-images`), set by the new parameter `app.firm_image_dir`. They are outside the web root and outside the database, so include the directory in backups.
- SQLite must be 3.35 or newer.
- The tests of the PDF and of the ISDOC file use veraPDF, Poppler and the ISDOC schema when they are installed (see CONTRIBUTING.md); CI requires them.

### Added

- Invoices. An invoice is issued by one firm to one customer, with lines, VAT per rate, the reverse charge with its supply code, a form without VAT for a firm that is not a VAT payer, and a total rounded to a whole crown if the firm chooses so. The amounts are calculated when the invoice is saved and stored; the screen, the PDF and the ISDOC file all show what is stored. See [docs/adr/0014-invoice-amounts-rounding-and-concurrent-edits.md](docs/adr/0014-invoice-amounts-rounding-and-concurrent-edits.md). The tax rules are subject to professional tax and legal review.
- Two people editing the same invoice cannot overwrite each other: a save from a form opened before another save is refused with a message, and every change takes a lock on the invoice, on SQLite and on PostgreSQL.
- PDF of an invoice, conforming to PDF/A-3a, with the ISDOC 6.0.2 file attached, a QR Platba+F code and the firm's logo, stamp and signature. Every invoice is drawn with one layout, the panel. See [docs/adr/0012-invoice-pdf-and-isdoc.md](docs/adr/0012-invoice-pdf-and-isdoc.md).
- A download of the PDFs of a period for the accountant, each with its ISDOC file embedded, in one ZIP. If a document cannot be drawn there is no ZIP, and the form names the document.
- [docs/invoicing.md](docs/invoicing.md): what the invoices support in this release, the ISDOC producer statement and the limits.

- The invoice list with the tabs All, Overdue and Trash, search, a firm filter and row actions. A deletion asks for a reason, the trash shows who deleted a document, when and why, and each invoice has a history of who changed what, its lines included.
- Copying an invoice: the form opens unsaved with the customer as the contact reads now, today's dates and the next number, and says when the lines hold a date or when the firm, series or customer can no longer be used.
- The invoice form: save issues the document, with live totals from the server, a customer picker per firm, reverse charge with its supply code, and a form without VAT for a non-payer firm.
- Number series per firm under the new Invoicing tab of a firm: format with date tokens and one sequence, default series, next number. A number is never used twice, the sequence restarts each year when the format has a year, and the format locks once the series has numbers. Deleted invoices go to a trash that keeps their number, and a number an edit gave up stays taken. See [docs/adr/0013-invoice-numbers-and-trash.md](docs/adr/0013-invoice-numbers-and-trash.md).
- Invoicing settings per firm: rounding of the total, constant symbol, texts above and below the items, and the contact printed on invoices.
- A logo and an image with stamp and signature on the firm form. PNG or JPEG in RGB, up to 1 MB and 3000 x 3000 pixels. Both stay outside the web root in `var/share/prod/firm-images`, so include that directory in backups.

### Changed

- SQLite 3.35 or newer is required, for the atomic counter of number series.
- The firm sections of the contact form are replaced by a scope per panel. With two or more active firms, the panels for roles, document defaults, invoicing e-mails and archive state have a dropdown: all firms, or one firm. A firm can use the shared values or have its own, for example a supplier for one firm and a customer for the other. Everything is saved with the form. A contact archived for all firms stays archived for every firm. The address book list stays on the shared values.
- The sidebar is grouped around documents and settings. Invoices are under Sales (Prodej), the address book under Contacts (Adresář), and Settings at the bottom holds Firms and the new item Import and export, which opens the history of imports. A group without items is not shown. The firm page has two tabs, Details and Invoicing, in place of a button; the invoicing tab holds the settings and the series. All addresses stay as they were.
- An identified person cannot issue invoices yet; the save is refused until the rules for it are reviewed.
- Text that the PDF font cannot print is refused when an invoice is saved, with the field and the characters named, so that the PDF and the ISDOC file never differ. Text in scripts such as Chinese or Arabic cannot be used in invoices yet.
- All firms now edits the document defaults, invoicing e-mails and archive state a contact shares, which the form did not offer before.

## [0.4.0] - 2026-11-01

Breaking release: every record gets a new kind of identifier. Read the upgrade notes before updating.

### Upgrading

1. Finish or cancel imports that are in progress, because their queued work cannot be read after the upgrade. Then stop the web server and cron (`app:import:health`, `app:import:purge`).
2. Let the workers drain the `async` and `registry` queues, until `bin/console messenger:stats` shows those two empty. The `failed` queue may hold messages. A message left in `async` or `registry` cannot be read afterwards; it is moved to `failed`, not lost, but its work has to be started again.
3. Stop the workers.
4. Back up the database and the `var/imports` directory.
5. Deploy the code and clear the cache (`bin/console cache:clear`).
6. Run the migrations with `bin/console doctrine:migrations:migrate`. The identifier migration cannot be reverted and cannot be rehearsed with `--dry-run` or `--write-sql`; the application refuses both. Run it through `bin/console`, not through a standalone `doctrine-migrations` binary, which does not have that protection.
7. Start the web server, cron and the workers.

Signed-in users stay signed in: a session from before the upgrade still loads, the user is refreshed from the database by e-mail address, and the organisation chosen in it stays chosen, because organisation identifiers do not change. Old URLs stop working (see below).

### Added

- Several firms in one organisation. The owner lists, adds, edits and archives them under Settings, with the register lookup of the contact form. A registration number is unique among the active firms of an organisation; an archived firm cannot be restored, and the number can be added again. The last active firm cannot be archived.
- Per-firm contact settings. With two or more active firms, the contact form has a section per firm for the due period, currency, document language, payment method and reverse charge. A firm without settings for a contact falls back to the contact's own defaults.
- Every audit entry records the aggregate root it belongs to, so the whole history of a contact is one query, including parts removed since. A contact link is recorded under the company only.
- Architecture decision records 9 to 11 on identifiers, several firms in one organisation, and audit roots and retention. The retention position is subject to professional tax and legal review.
- Cross-tenant references are refused when a record is written.

### Changed

- **Breaking:** every entity is identified by a UUIDv7 instead of a whole number. A migration converts existing data and cannot be reversed, so back up the database first.
- **Breaking:** old contact and import URLs stop working, because they contain the old numbers. Open the records again from the lists.
- **Breaking:** a file exported earlier has an `id` column of whole numbers. A row with such a value is a row error on import; match by registration number, VAT ID or e-mail address instead. Files exported after the upgrade carry the new identifiers and can be imported again.
- Messages that cannot be decoded go to the failed transport instead of being discarded.

## [0.3.1] - 2026-10-31

### Changed

- The column matching step says once where the proposals come from and marks only columns matched by a similar name.
- Help texts under fields fit on one line, and a test keeps them at 60 characters or fewer.

## [0.3.0] - 2026-10-31

### Added

- Generic import and export. Import CSV and XLSX files in the background: encoding, delimiter and header row are detected, columns are mapped with remembered mappings, a dry-run check lists every row error (downloadable as an error file), and three modes are offered (add missing, update existing, full replace, which archives records missing from the file and needs the number of records typed as confirmation). An import can be undone for 30 days, also one that failed after it started writing, and a failed undo can be started again. Contacts are the first target, with a preset for the iDoklad contact export. Files up to 50 MB and 200,000 rows; files and staged rows are deleted 30 days after an import ended. Documented in `docs/import.md`.
- Commands `app:import:purge` (deletes old files and staged rows) and `app:import:health` (fails imports that stopped moving and starts the next queued one); run both from cron.
- UI components for the import screens (stepper, progress bar, radio cards, mapping row) and notices in the app shell when an import ends.
- Contacts have a website and a mobile phone.
- Audit entries carry an optional correlation id that groups the changes of one operation.
- Architecture decision records for the audit correlation id and the asynchronous import pipeline.
- A floating action bar holds the primary action of every form and wizard step, so it stays in reach on long pages.
- Closed design token scales for radius, control height, type, borders and shadows, shown in the component catalogue and enforced by a test.

### Changed

Upgrading: run the database migrations (`bin/console doctrine:migrations:migrate`); they add the import tables, the audit correlation id, the e-mail comparison key and the contact fields. Start a background worker (`messenger:consume async registry`, one worker on SQLite) and schedule the two import commands; see `docs/import.md`. Set `upload_max_filesize` and `post_max_size` to at least `50M`.

- The address book has import and export actions in its header; the CSV export is now an export menu with CSV and XLSX, and it exports the filtered list.
- Contact roles and kinds accept their translated names when imported.
- Background messages run inside the tenant they were sent from; the default `flock` lock works on a single host, use `LOCK_DSN` for several.
- Every response forbids content type sniffing (`X-Content-Type-Options: nosniff`).
- Forms are validated on submit: every invalid field is marked with its message, the input is kept, focus moves to the first invalid field and the page title starts with "Error". Primary buttons are never disabled for validation.
- Control borders meet 3:1 non-text contrast, and fields side by side line up whatever text sits under them.

### Fixed

- The register lookup names the rate limit (HTTP 429) instead of a generic failure.

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
