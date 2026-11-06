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
- Sending by e-mail adds `symfony/mailer` and `symfony/scheduler`, and requires the PHP extension `sodium`. Set `MAIL_SECRET_KEYS` (keys that seal the firms' mail server passwords) and `DEFAULT_URI` (the public https address that links in mail lead to). See [docs/sending.md](docs/sending.md).
- The worker command is now `messenger:consume scheduler_foresi mail async registry --memory-limit=256M --time-limit=3600`: it delivers mail and runs the timed jobs. Update your systemd or supervisor unit.
- PDFs as sent are kept in `var/share/<env>/sent-documents`. Include that directory in backups, together with the database.
- Recurring invoices and reminders run in the same worker, from the `scheduler_foresi` transport; no new process is needed. Their day follows the organisation's time zone, by default the zone of its first active firm's country. Set another with `bin/console app:tenant:timezone <tenant-id> <zone>`. See [docs/adr/0017-tenant-timezone.md](docs/adr/0017-tenant-timezone.md).
- The tests of the PDF and of the ISDOC file use veraPDF, Poppler and the ISDOC schema when they are installed (see CONTRIBUTING.md); CI requires them.
- A firm's bank accounts move from the list on the firm into records of their own. The migration keeps their order and makes the first one the primary account. The list held no currency, so each account gets the home currency of the firm's country: CZK in Czechia, EUR in a euro-area country and USD in the United States. A firm in any other country gets CZK. Check the currency of each account in the firm's bank settings, and correct it for an account kept in another currency.
- Breaking for country modules: the public interface `InvoicingCountry` has a new method, `settlementLimit(Currency $currency): ?int`, the most minor units a firm of the country may write off on an invoice in the currency, or null for none. A third-party country module must implement it; return null to keep small differences unsettled.
- Payments and bank pairing add `zbateson/mail-mime-parser` (BSD-2-Clause), which reads bank e-mail, and `directorytree/imapengine` (MIT), which polls the bank inbox; run `composer install`. The worker command is unchanged.
- Bank e-mail is off until it is set up. The new environment variables are all empty by default: `BANK_INBOX_ADDRESS` (the address template with `{token}`, such as `bank+{token}@example.com`; the mailbox must deliver plus addresses), `BANK_INBOX_TRUSTED_AUTHSERV` (the authserv-id of the inbox's own receiving server, which must add `Authentication-Results` at the top of every message), `BANK_INBOX_TRUSTED_ARC` (trusted ARC sealers), `BANK_INBOX_HOST`, `BANK_INBOX_PORT` (993), `BANK_INBOX_ENCRYPTION` (`ssl` or `starttls`), `BANK_INBOX_USER`, `BANK_INBOX_PASSWORD`, `BANK_INBOX_FOLDER` (`INBOX`) and `INBOUND_WEBHOOK_SECRET` (the bearer secret of the optional webhook `POST /inbound/mail`). Inbound addresses are sealed with `MAIL_SECRET_KEYS`. See [docs/bank-pairing.md](docs/bank-pairing.md).
- Raw bank e-mails are kept in `var/share/<env>/inbound-mail`, as the evidence of the payments recorded from them. Include that directory in backups, together with the database.
- Advances add columns to `invoice`, `invoice_line` and `firm_reminders`; run the migrations. Every stored document stays an invoice. A firm gets the standard series of advance invoices (`Z9{YYYY}{NNNN}`) and of tax documents on payment (`D8{YYYY}{NNNN}`) on first use; set up others under the firm's invoicing settings before the first advance if you number differently.
- Corrective tax documents add columns to `invoice` and `payment`; run the migrations. Every stored payment is incoming. A firm gets the standard series of corrective tax documents (`O7{YYYY}{NNNN}`) on first use.
- The export state adds the columns `exported_at` and `changed_after_export_at` to `invoice`, and the month filter an index on a firm's documents by tax point; run the migrations. Every stored document starts as not exported, so the first pack of a month takes all its documents.
- The move from iDoklad adds tables for the iDoklad keys, the downloaded snapshot, the jobs, the links of imported records and the reports of checks and imports, and columns for the import source, run and original PDF of documents and payments; run the migrations. Its PDFs are kept in `var/share/<env>/idoklad-documents` and the originals of imported documents in `var/share/<env>/original-documents`; include both directories in backups. The jobs run on the `async` transport of the existing worker, and the iDoklad keys are sealed with `MAIL_SECRET_KEYS`. See [docs/idoklad.md](docs/idoklad.md).

### Added

- The move from iDoklad: a wizard per firm under the list of imports connects an iDoklad account with its API keys (the Oblíbený or Prémiový tariff), downloads it into a snapshot (page by page, the PDFs in batches, later only what changed) through a read-only allow-list of GET paths, shows the API requests used this month, and checks the import as a run that is rolled back. The report counts what would be created, matched, refused or differs by area, sums documents by year in iDoklad and in Foresi, and lists each refused record with its reason and each document whose totals differ with both amounts. The import brings contacts, number series that count on from iDoklad's last number, issued and advance invoices priced again with the Czech VAT rates of their day back to 2013 and kept with iDoklad's PDF as their original, payments with their tax documents and the deductions of advances, and recurring invoices as paused templates; it sets the day reminders start from. An import can be undone for 30 days while nothing done in Foresi stands on it. See [docs/idoklad.md](docs/idoklad.md) and [docs/adr/0024-idoklad-import.md](docs/adr/0024-idoklad-import.md).
- Advance invoices, tax documents on received payment and their settlement on the final invoice. A payment of an advance, by hand or from the bank, gets its tax document in the same step when VAT arises on it; one that cannot be issued waits in a list with its deadline. A final invoice deducts the customer's tax documents with negative lines per rate, each once, and may reach zero or less. Each document shows its chain of links. See [docs/invoicing.md](docs/invoicing.md#advances) and [docs/adr/0021-advances-and-tax-documents-on-payment.md](docs/adr/0021-advances-and-tax-documents-on-payment.md).
- Corrective tax documents: a credit or debit note from the row menu of an issued invoice, with its reason, its type (a change or an error) and its decisive date, at the invoice's rates, capped per rate under a lock. Corrections lower or raise what is left to pay; an invoice paid above that is to refund, and a refund is recorded by hand or paired by a person from an outgoing bank movement. A cancelled or refunded advance payment corrects its delivered tax document by itself. ISDOC types 2, 3 and 6 name the original document. Corrections have their own list, series `O7{YYYY}{NNNN}` and mail template. See [docs/invoicing.md](docs/invoicing.md#corrections) and [docs/adr/0022-corrective-tax-documents.md](docs/adr/0022-corrective-tax-documents.md).
- Series have a document kind, with a default series per kind and firm.
- Reminders can take advance invoices too, with a switch of their own.
- Recurring templates can issue advance invoices.
- Invoices. An invoice is issued by one firm to one customer, with lines, VAT per rate, the reverse charge with its supply code, a form without VAT for a firm that is not a VAT payer, and a total rounded to a whole crown if the firm chooses so. The amounts are calculated when the invoice is saved and stored; the screen, the PDF and the ISDOC file all show what is stored. See [docs/adr/0014-invoice-amounts-rounding-and-concurrent-edits.md](docs/adr/0014-invoice-amounts-rounding-and-concurrent-edits.md). The tax rules are subject to professional tax and legal review.
- Two people editing the same invoice cannot overwrite each other: a save from a form opened before another save is refused with a message, and every change takes a lock on the invoice, on SQLite and on PostgreSQL.
- PDF of an invoice, conforming to PDF/A-3a, with the ISDOC 6.0.2 file attached, a QR Platba+F code and the firm's logo, stamp and signature. Every invoice is drawn with one layout, the panel. See [docs/adr/0012-invoice-pdf-and-isdoc.md](docs/adr/0012-invoice-pdf-and-isdoc.md).
- The pack for the accountant: one firm and one month in a ZIP, with a folder per kind, each document as a PDF/A-3 with its ISDOC file beside it, named by issue date, customer, amount and variable symbol, and `seznam.xlsx` with the documents and a VAT recap per treatment and rate. Tax documents count by tax point, advance invoices by issue date. By default it takes only what is not yet exported or changed after export, and the page shows how many documents of each kind it holds before the download. If a document cannot be drawn there is no ZIP, and the form names the document. See [docs/invoicing.md](docs/invoicing.md#for-the-accountant) and [docs/adr/0023-accountant-pack-and-export-state.md](docs/adr/0023-accountant-pack-and-export-state.md).
- The export state of each document, as iDoklad keeps it: not exported, exported or changed after export, with the time. A finished pack marks its documents; edits stay allowed and mark a document changed. The invoice lists show it in an Export column, filter by it, and offer "Clear export state" in the row menu of an exported document.
- A month filter on every document list, and the sums of what the list shows over all its pages under it: count, base, VAT and total, and a line per treatment and rate, in crowns at each document's own exchange rate.
- Export of a document list as it is shown, over all its pages, to CSV or XLSX, from the Export menu of the list.
- The start page shows the invoicing of one firm or all: invoiced and output VAT this month and last month, unpaid and overdue with overdue amounts by age, the five largest debtors, and a reminder when last month has documents the accountant has not received yet, with a link to its pack.
- [docs/invoicing.md](docs/invoicing.md): what the invoices support in this release, the ISDOC producer statement and the limits.

- The invoice list with the tabs All, Overdue and Trash, search, a firm filter and row actions. A deletion asks for a reason, the trash shows who deleted a document, when and why, and each invoice has a history of who changed what, its lines included.
- Copying an invoice: the form opens unsaved with the customer as the contact reads now, today's dates and the next number, and says when the lines hold a date or when the firm, series or customer can no longer be used.
- The invoice, advance, correction and recurring forms share one layout, as iDoklad: labels beside the fields (above them on a phone), two bands of details, the lines and totals, and an action bar; the payment details show the account, its number and IBAN once.
- The invoice form: save issues the document, with live totals from the server, a customer picker per firm, reverse charge with its supply code, and a form without VAT for a non-payer firm.
- Any active contact can be invoiced, whatever its role; contacts archived for the firm cannot. Customers come first in the picker, and a saved invoice makes the contact a customer of that firm.
- A contact created with the plus on the invoice form comes back picked, with its defaults, while the form keeps what was typed.
- Numbers in the line fields are grouped as you type. The fields and the server read `1 000,50`, `1000.5` and `1,000.50`.
- Every invoice shows one status on the list, the form and the history: issued, delivered, overdue or in the trash.
- Number series per firm under the new Invoicing tab of a firm: format with date tokens and one sequence, default series, next number. A number is never used twice, the sequence restarts each year when the format has a year, and the format locks once the series has numbers. Deleted invoices go to a trash that keeps their number, and a number an edit gave up stays taken. See [docs/adr/0013-invoice-numbers-and-trash.md](docs/adr/0013-invoice-numbers-and-trash.md).
- Invoicing settings per firm: rounding of the total, constant symbol, texts above and below the items, and the contact printed on invoices.
- A logo and an image with stamp and signature on the firm form. PNG or JPEG in RGB, up to 1 MB and 3000 x 3000 pixels. Both stay outside the web root in `var/share/prod/firm-images`, so include that directory in backups.
- Sending invoices by e-mail as a link to the PDF, through each firm's own SMTP server: a send dialog with the contact's invoicing addresses, a copy to the sender and the text filled in from the firm's template in the document's language; "Send and save" on the form; retries with a visible failure; a public link page with the document and its payment details; read state; marks on the invoice list, sends on the invoice's history with the PDF as sent, a send log under Sales, and notices for failed and waiting sends. See [docs/sending.md](docs/sending.md), [docs/adr/0015-sending-and-public-links.md](docs/adr/0015-sending-and-public-links.md) and [docs/adr/0016-scheduled-jobs.md](docs/adr/0016-scheduled-jobs.md).
- E-mail settings of a firm: templates per document language with readable variables, the mail server and a test e-mail.
- Timed jobs per organisation on one schedule, run by the worker.
- Recurring invoices under Sales: a template with the invoice's fields, a schedule (daily to yearly, every 1 to 99, the last day of the month, tax point on the issue date or the month's last day, due days, an end rule) and period variables with offsets, `{document_number}` among them. A preview shows the next five issues. A template can start from an invoice, and be paused, resumed, ended, deleted before its first issue and issued now. See [docs/recurring.md](docs/recurring.md).
- A daily run from 05:00 in the organisation's time zone issues each due occurrence exactly once, catches up after downtime, sends the invoice to the customer with a copy to the firm, or holds it under the new To send tab of the invoice list; one more than 3 days late is held. A template saved or resumed after the day's run is issued at once. Failures are recorded on the template, shown in a notice and mailed to the firm once per run. See [docs/adr/0018-recurring-occurrences.md](docs/adr/0018-recurring-occurrences.md).
- Automatic reminders of overdue invoices: per firm a switch, the days to the first and to each next reminder, a minimum amount and up to 10 reminders; a Reminder template per language; a Send reminders flag per contact; reminders by hand from the row menu; reminders in the history and the send log with a type filter. What an invoice still owes comes from the recorded payments: an invoice paid in full is never reminded, by hand or by the run, nor listed in the settings' preview, and a part-paid one is reminded of what is left to pay, in the text, in the PDF, whose payment box then says Left to pay, in its QR code and in the preview. While reminders are off, the settings show which reminders they would send if they were switched on today. A reminder whose invoice was paid or trashed meanwhile is cancelled before it leaves. See [docs/reminders.md](docs/reminders.md).
- The time zone of an organisation for its daily work, set with `app:tenant:timezone`.
- Payments. "Pay" in the row menu of the invoice list records a payment in a dialog: date, amount (what is left, prefilled), method and note; a part payment is allowed, and an overpayment after a warning. One payment can be split over several invoices. The invoice shows paid, partly paid or overpaid, derived from its payments and never stored, lists its payments with Remove, and its history shows each payment and removal. See [docs/adr/0019-payments-and-allocations.md](docs/adr/0019-payments-and-allocations.md).
- Bank pairing from Komerční banka payment notifications. Each bank account gets a secret inbound address; Foresi takes the mail from one operator mailbox over IMAP, or from a relay through a webhook, records the movements and pays the matching invoice by the variable symbol and the amount when the rules leave exactly one answer. Only a message the inbox's own receiving server verified by DMARC pays anything by itself; an unverified one is recorded with suggestions, and a forged one is rejected. Every pairing can be undone. See [docs/bank-pairing.md](docs/bank-pairing.md) and [docs/adr/0020-bank-notification-mail.md](docs/adr/0020-bank-notification-mail.md).
- A Bank tab on each firm, with the accounts and their currency, the inbound addresses with setup steps for MojeBanka Business and KB+, and the switches automatic pairing (on) and small-difference settlement (off; up to 0,99 Kč in CZK).
- Bank in the navigation: incoming and outgoing movements, the unmatched queue with suggestions, pairing by hand over one or more invoices with Undo, unpairing, and the bank e-mails with Show, Try again, Record anyway and Dismiss. A message waiting for over an hour shows as stuck. Failed bank e-mails are read again every hour when a newer parser can read them.

### Changed

- SQLite 3.35 or newer is required, for the atomic counter of number series.
- The firm sections of the contact form are replaced by a scope per panel. With two or more active firms, the panels for roles, document defaults, invoicing e-mails and archive state have a dropdown: all firms, or one firm. A firm can use the shared values or have its own, for example a supplier for one firm and a customer for the other. Everything is saved with the form. A contact archived for all firms stays archived for every firm. The address book list stays on the shared values.
- The sidebar is grouped around documents and settings. Invoices are under Sales (Prodej), the address book under Contacts (Adresář), and Settings at the bottom holds Firms and the new item Import and export, which opens the history of imports. A group without items is not shown. The firm page has two tabs, Details and Invoicing, in place of a button; the invoicing tab holds the settings and the series. All addresses stay as they were.
- The daily runs send as the system: they skip the per-user minute limit and use at most 80% of a firm's daily budget.
- Firms is now My firms (Moje firmy) in the interface, and its empty text says these are your own companies that issue documents.
- An error about the firm links to the firm's settings in a new tab, and a firm without a bank account says so in the payment section before the save. Every link in the error list lands on a field that takes the focus.
- The lines of the invoice form: a discount is a sub-row of its line with its own deduction, moving and removing a line are in a row menu, and the total reads with VAT for a payer. Amounts on screen show Kč for CZK.
- A Czech document writes crowns as Kč in its PDF, on its link page and in its mail; an English one keeps CZK, and ISDOC keeps the ISO code.
- The order number and the description moved into More details, which opens when it holds something other than the defaults, or a mistake.
- An identified person cannot issue invoices yet; the save is refused until the rules for it are reviewed.
- Text that the PDF font cannot print is refused when an invoice is saved, with the field and the characters named, so that the PDF and the ISDOC file never differ. Text in scripts such as Chinese or Arabic cannot be used in invoices yet.
- All firms now edits the document defaults, invoicing e-mails and archive state a contact shares, which the form did not offer before.
- A firm's bank accounts are records: each has a currency and a label, one is the primary account that new invoices get, and an account is archived instead of deleted, so documents keep it. The firm form no longer lists them.
- The status of an invoice includes the payment states, in the order trash, overpaid, paid, overdue, partly paid, delivered, issued. A paid invoice is not overdue. The invoice list has the tabs All, Paid, Unpaid, Overdue and Trash.
- An invoice with a payment cannot go to the trash; remove the payments first.
- The public link page of a paid invoice says Paid and shows no way to pay it again. After a part payment it shows what is left to pay, without the QR code.

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
