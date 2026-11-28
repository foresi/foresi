# Upgrading

How to move a Foresi instance to a newer release: the steps of every upgrade,
the one stop to make when you skip many versions, and what each release asks of
an operator or integrator. Installing, deploying and rolling back are in
[operations](operations.md).

Every merged change that users notice is its own release, so skipping versions
is normal. The migrations run in order whatever the gap. What else a release
needs (a new environment variable, a PHP extension, a one-way migration, a
command to run once) is in its notes, under "Upgrading", and all of them are
collected [below](#upgrade-notes-by-version).

## How to upgrade

1. Read the [upgrade notes](#upgrade-notes-by-version) of every version after
   the one you run, up to the one you deploy. A release that is not listed
   asks nothing beyond the steps here. The full notes of each release are on
   the [releases page](https://github.com/foresi/foresi/releases), and up to
   0.5.0 in the [changelog](../CHANGELOG.md).
2. Pick a quiet time. Check that the queues are drained: `bin/console
   messenger:stats` shows no waiting `mail` or `async` messages, no import is
   running, and `bin/console messenger:failed:show` holds nothing new.
3. Take a backup (`ops/backup.sh --tag pre-upgrade`) and confirm
   `/var/lib/foresi/backup.status` says `result=ok`.
4. Deploy the new tag ([deploy](operations.md#deploy)). The deploy dumps again
   before the migrations, migrates with `doctrine:migrations:migrate`, stores
   the default views of the lists with `app:views:sync`, and ends with
   `messenger:stop-workers`, so the supervisor starts the workers on the new
   code.
   When a release adds a module next to existing data, the release notes name
   the one-time command that fills it from what is already stored, for example
   `bin/console margin:recalculate` after installing Margin or
   `bin/console cashflow:pairing:backfill` after installing Cash flow. Both are
   idempotent.
5. Check `/health`, sign in, and watch the log and the `failed` queue for an
   hour.

If something goes wrong, [roll back](operations.md#rollback). A rollback does
not revert migrations; after a migration that is not backward compatible, restore
the `pre-migrate` snapshot with the code.

Some modules say more about their own upgrades: [import](import.md#upgrading),
[mail](mail.md#upgrading) and [bank pairing](bank-pairing.md#upgrading).

## Several versions at once

You may jump over any number of releases in one deploy, with one exception: a
database must run 0.8.0 before it moves to 0.9.0 or newer. 0.8.0 is the last
release with the global migration history, and 0.9.0 replaces it with one
baseline per module, which only a database on 0.8.0 can be rebased onto. So an
instance on 0.7.x or older upgrades in two steps:

1. Deploy 0.8.0 and let it migrate, following the notes of every version up to
   0.8.0.
2. Deploy 0.9.0 or newer and rebase the database, as
   [the module baselines](#the-module-baselines) describe, following the notes
   from 0.9.0 on.

An instance on 0.8.0 goes straight to the module baselines; one on 0.9.0 or
newer has no stop to make.

### The module baselines

Up to 0.8.0 every migration was global, in `migrations/`. From 0.9.0 each
module has its own, starting with one baseline that creates its tables as the
global history left them ([ADR 0034](adr/0034-module-packages.md)). A database
migrated before moves onto the baselines once, by its version rows alone:

1. If the instance runs a release older than 0.8.0, deploy 0.8.0 first and
   let it migrate.
2. Take a backup: `ops/backup.sh --tag pre-rebase`.
3. Deploy 0.9.0 or newer. The deploy sees the global history, runs no
   migration and switches to the new release; the schema is already the one it
   expects.
4. From the live release, run `bin/console app:migrations:rebase`. It checks
   that every global migration has been executed and that the schema matches
   the mapping, then replaces the 108 version rows with the baselines of the
   installed modules in one transaction. No table changes. On any other state
   it refuses and changes nothing.
5. `bin/console doctrine:migrations:up-to-date` must report no pending
   migration. The next deploys migrate as before.

`app:migrations:rebase --check` changes nothing and tells the state by its
exit code: 0 for a new database or one on the baselines, 2 for one on the
global history, 1 otherwise.

### Modules that change their name

A migration is known by its class name, so a module that moves to another
namespace would run its migrations again. `app:migrations:rename` gives the
executed versions of such a module the new namespace, by their rows alone: in
one transaction, with no DDL, and with no effect when nothing is left to
rename. The map is `MigrationHistoryRename::RENAMES`: `Purchasing` became
`ReceivedDocuments`, `Sales` became `Deals` and `Documents` became
`DocumentTemplates`. `ops/deploy.sh` runs it before it looks at the
migrations. `--check` changes nothing and tells the state by its exit code: 0
when nothing is left to rename, 2 when a rename is due, 1 when the old and the
new row of one version both exist (the deploy stops).

### The address book as its own module

The address book (the contact screens, their API and MCP tools) is the module
`foresi/contacts`; Party, in the core, keeps the record of a contact and the
firm. An instance that shows contacts installs `foresi/contacts`
(`composer require foresi/contacts`); one without it keeps every stored
contact, but has no Contacts item, no client on its projects and no contact
link on its records. No table moves. A Party migration renames the stored
events of a contact from `party.contact.*` to `contacts.contact.*` in the event
log and the webhook deliveries, and gives an endpoint that took `party.*` the
events of `contacts.*` too, on every instance. The token scope `contacts` stays
Party's, so the firm's tools keep working without the address book.

## Upgrade notes by version

The "Upgrading" section of every release that has one, newest first, grouped
by minor version. Each heading links the release and names its pull request.
The text is the release's own, with links to other documents made relative to
this folder.
0.5.0 and 0.4.0 come from the [changelog](../CHANGELOG.md).

A pull request whose Release notes have `### Upgrading` adds its section here,
at the top of its minor version, with the same text (see
[CONTRIBUTING](../CONTRIBUTING.md#releases)).

### 0.13

#### [0.13.0](https://github.com/foresi/foresi/releases/tag/v0.13.0): any ISO 4217 currency (#PRNUM)

- No migration: amounts and their currency codes are stored as before.
- REST API and MCP: every money object gains `minor_units`; read it instead of assuming two decimals. A currency input takes any current ISO 4217 code and refuses an unknown one with `400 invalid_parameter`; a decimal amount has at most the currency's minor units. `GET /api/v1/currencies` and the MCP tool `foresi_list_currencies` list the codes. Reconnect MCP clients so they reload their tool lists.
- A module written for module API 2.x must move to 3.0 (see `docs/modules.md`):
  - `Currency::CZK` → `Currency::of('CZK')`
  - `->value` → `->code()`
  - `cases()` → `Currencies::current()`
  - `enumType` → `type: 'currency'`
  - no `match` arms on the cases and no `instanceof \BackedEnum` checks of a currency.

### 0.12

#### [0.12.0](https://github.com/foresi/foresi/releases/tag/v0.12.0): modules get the names they keep; contacts, timesheet and banking stand on their own, and the core runs without ui (#366)

- Deploy runs `bin/console app:migrations:rename` before `doctrine:migrations:migrate`. The migrations rename tables and rewrite stored event types, webhook subscriptions, saved views and token scopes.
- REST paths change: `/api/v1/sales/deals` → `/api/v1/deals`, `/api/v1/sales/stages` → `/api/v1/deal-stages`, `/api/v1/sales/lost-reasons` → `/api/v1/lost-reasons`. Web paths `/sales/*` → `/deals/*`, `/purchasing/*` → `/received-documents/*`. No redirects.
- Event types change prefix: `sales.` → `deals.`, `purchasing.` → `received-documents.`, `documents.` → `document-templates.`, `party.contact.` → `contacts.contact.`. Existing webhook subscriptions are rewritten.
- Scopes: `sales` → `deals`. The firm tools (`foresi_get_firm`, the firm VAT tools, `GET /api/v1/firm`) need the new `firm` scope; every existing token with `contacts:read`/`write` also gets `firm:read`/`write`. Reconnect MCP clients so they reload their tool lists.
- Packages: `foresi/received-documents`, `foresi/deals`, `foresi/document-templates` and `foresi/contacts` replace `foresi/purchasing`, `foresi/sales` and `foresi/documents`. `foresi/core` no longer includes `foresi/webhooks`; require it on its own. An instance that tracks time must require `foresi/timesheet`.
- Workers: `bin/console app:worker:receivers` prints the queues to consume; the `webhooks` queue exists only where the module is installed.
- A module written for module API 1.x must move to 2.0 (see `docs/modules.md`). A country module that formats bank accounts implements `BankingCountry`; a module that released payment sources implements `BankPaymentRelease`.

### 0.11

#### [0.11.13](https://github.com/foresi/foresi/releases/tag/v0.11.13): each instance locks in its own share directory (#322)

- Nothing to do. An instance with `LOCK_DSN=flock` or no `LOCK_DSN` uses its own lock directory after the update; one that set `LOCK_DSN=flock:///path` by hand keeps that directory and may drop the setting.

#### [0.11.12](https://github.com/foresi/foresi/releases/tag/v0.11.12): Symfony 7.4.24 with the fixes of 18 security advisories (#321)

- Update to this release. Nothing else to do.

#### [0.11.9](https://github.com/foresi/foresi/releases/tag/v0.11.9): one intake for bank movements from every channel, with statement gaps (#318)

- Run the migrations: they add the table `bank_statement` and give every existing bank movement the channel `mail` and the parser of its message as its source.
- `Foresi\Banking\Domain\MessageTrust` is now `Foresi\Banking\Contract\MovementTrust`. Domain is internal, so no alias is kept; a module that used the old name uses the new one.

#### [0.11.8](https://github.com/foresi/foresi/releases/tag/v0.11.8): several rate sources, ECB reference rates and the legal rate per country (#317)

- A migration keys `exchange_rate` by its source and adds the `base` column and the `exchange_rate_setting` table. The worker reaches `www.ecb.europa.eu` only once a firm chooses the ECB.

#### [0.11.2](https://github.com/foresi/foresi/releases/tag/v0.11.2): modules of any vendor, the module API range, and an external example module in CI (#311)

- Modules: add `"foresi/module-api": "^1.0"` to `require` of your module's `composer.json`. Without it, or with a range 1.0.0 does not satisfy, the container is not built, and the error names the module. Composer itself refuses an incompatible range.
- Modules: replace the deprecated names below with the new ones. Until then each old name logs a deprecation. Type-hint `Foresi\Identity\Contract\StaffCheck` instead of the class.
- Modules of another vendor: the namespace gives the id, the bundle class, the extension alias, the asset namespace and the MCP tool prefix. `Acme\Fleet\` is id `acme-fleet` with bundle `Acme\Fleet\AcmeFleetBundle`, alias `acme_fleet`, assets `@acme-fleet` and tools `acme_fleet_…`. The manifest must declare that id and that bundle, and the namespace must be in the package's `autoload.psr-4`.
- Breaking for code that used internals: `InstalledModules::manifestFrom()` takes the id of each installed module by its package (`array<string, string>`) instead of a list of packages. The manifest of a module is refused when its bundle is not `<namespace><Studly id>Bundle` or its namespace is not in its autoload.
- Operators: deployments run `bin/console app:modules:check` after `composer install`. If you deploy with a script of your own, add that step before the migrations.

#### [0.11.0](https://github.com/foresi/foresi/releases/tag/v0.11.0): the extension contract in Contract namespaces and foresi.* tags (#307)

- Modules: replace the deprecated class names below with the new ones and collect or tag services by the `TAG` constant of their interface. Until then each old name logs a deprecation. Nothing else is needed for public names.
- Breaking for code that used internals: the static helpers `text`, `flag`, `limit`, `pagingSchema`, `idSchema`, `dateSchema` and `choiceSchema` moved from `Foresi\Shared\Infrastructure\Mcp\ToolArguments` to `Foresi\Shared\Contract\Mcp\ToolSchema`. Change the calls.
- Breaking for code that used internals: the tags of internal interfaces are renamed with no old name, such as `app.billed_work`, `app.event_read_access`, `app.operator_task`, `app.idoklad_import_stage` and `foresi.list_definitions`. `foresi.content_security_policy_source` is now `foresi.shared.content_security_policy_source`. Tag or collect these by their interface's constant.
- Operators: nothing to do.

### 0.10

#### [0.10.28](https://github.com/foresi/foresi/releases/tag/v0.10.28): the E-mail activity type and the project as an activity target (#299)

- Run `bin/console doctrine:migrations:migrate`, which adds the E-mail activity type.

#### [0.10.27](https://github.com/foresi/foresi/releases/tag/v0.10.27): the Mail module takes the inbound mail intake out of Banking (#298)

- Rename your `BANK_INBOX_*` settings to `MAIL_INBOUND_*`. For this release Foresi still reads an old name when the new one is empty, and logs a deprecation warning for it. The old names stop working in the next release.
- Run the migrations. They copy the stored bank mail into Mail with the same ids. Raw files stay where they are on disk.
- During a rolling deploy an old and a new worker may each poll once; dedup stores every mail once.
- Messages stored before the upgrade have no Message-ID, thread headers or sent date in Mail, and their generic verdict is `unknown`. Their bank trust is unchanged.
- The same mail sent to two bank addresses is now stored once per address, since dedup is per address.

#### [0.10.21](https://github.com/foresi/foresi/releases/tag/v0.10.21): planned-item mappings and the supplier's default recipient (#292)

- Run the migrations.
- The events worker must run: Margin recalculates shares when Cash flow pairs or unpairs a document, through an asynchronous event listener. Without the worker, those shares stay as they were until the next change of the document.
- Setting a mapping or a default recalculates the documents it applies to, so nothing has to be run after the upgrade. If the events worker was stopped while documents were paired or unpaired, run `bin/console margin:recalculate` to count every document's shares again.

#### [0.10.19](https://github.com/foresi/foresi/releases/tag/v0.10.19): the Margin module with line and document mappings and stored cost shares (#289)

- To use Margin, require `foresi/margin`, run the migrations, and run `bin/console margin:recalculate` once so that documents recorded earlier get their shares.
- API clients that set received-document lines should send back the `id` of each line they keep. Otherwise every line becomes new, and its mappings in Margin are dropped.

#### [0.10.15](https://github.com/foresi/foresi/releases/tag/v0.10.15): a cancelled document goes to the trash and can be restored (#286)

- Run `bin/console doctrine:migrations:migrate`: Documents adds the trash columns.

#### [0.10.6](https://github.com/foresi/foresi/releases/tag/v0.10.6): deals with stages, lines from the catalog, value per currency and follow-ups (32f) (#277)

- Run `bin/console doctrine:migrations:migrate`: Sales adds its tables and seeds the stages and lost reasons.
- API tokens need the new scope `sales:read` or `sales:write` to use deals.

#### [0.10.0](https://github.com/foresi/foresi/releases/tag/v0.10.0): sections with headings inside a navigation area (#241)

- Plugins: `NavigationItem` takes the area id as its first argument instead of a group key, and the `area:` argument is gone. Name a section of the area with `section:`; an unknown or missing section goes to the area's last section. A plugin area can define its sections with `NavigationSection`. `NavigationItem::GROUPS` and the Twig function `nav_groups()` are removed. See `docs/design/navigation.md`.

### 0.9

#### [0.9.27](https://github.com/foresi/foresi/releases/tag/v0.9.27): meetings, calls and tasks on a client's timeline and in the calendar (32d) (#243)

- Run `bin/console doctrine:migrations:migrate`, which adds the activity tables and the four default types.

#### [0.9.26](https://github.com/foresi/foresi/releases/tag/v0.9.26): the VAT module with its periods, the Czech rules and the payment details (33e) (#240)

- Run `composer install` and `bin/console doctrine:migrations:migrate`, which adds the `vat_settings` table. Add the day the firm became a VAT payer under **Settings → Firm → VAT**: no period is computed before a stated day.

#### [0.9.25](https://github.com/foresi/foresi/releases/tag/v0.9.25): the VAT payer days come from the firm's VAT statuses (33d) (#239)

- Run `bin/console doctrine:migrations:migrate`. It moves the VAT payer statuses of the Taxes and contributions profile to the firm's VAT statuses, each from its day, and cannot be reverted. Check the firm's VAT tab afterwards, and set a quarterly period there if yours is not monthly.

#### [0.9.24](https://github.com/foresi/foresi/releases/tag/v0.9.24): the firm's VAT status with dates and the period length (33c) (#236)

- Run `bin/console doctrine:migrations:migrate`. It adds the `party_vat_status_period` table and turns the firm's current VAT status into its first status, with no day. Open **Settings → Firm → VAT** and add the day the status began, so VAT can be counted from the right day.

#### [0.9.23](https://github.com/foresi/foresi/releases/tag/v0.9.23): contracts from templates, signed by hand, on the contact's Documents tab (#232)

- Run `bin/console doctrine:migrations:migrate`, which adds the Documents tables and the NDA type.
- Run `composer install`: the PDF library `tecnickcom/tc-lib-pdf` is now required by the shared core instead of Invoicing.

#### [0.9.16](https://github.com/foresi/foresi/releases/tag/v0.9.16): personal iCal feed addresses that any module can fill (32c) (#230)

- Run `bin/console doctrine:migrations:migrate`, which adds the `calendar_feed` table.
- Feed addresses are built on the host the person is using. The uids of the entries use the host of `DEFAULT_URI`, so set it to the public address.

#### [0.9.0](https://github.com/foresi/foresi/releases/tag/v0.9.0): one baseline migration per module replaces the global history (#215)

- Breaking for operators: the migrations move into the modules ([ADR 0034](adr/0034-module-packages.md)). Each module now has its own migrations, starting with one baseline that creates its tables, and the global history in `migrations/` is gone. A database migrated before moves onto the baselines once, by its version rows alone ([operations](operations.md#the-module-baselines)):
  1. If the instance runs a release older than 0.8.0, deploy 0.8.0 first and let it migrate.
  2. Back up: `ops/backup.sh --tag pre-rebase`.
  3. Deploy this release. `ops/deploy.sh` sees the global history, runs no migration and switches to the new release; the schema is already the one it expects.
  4. Run `bin/console app:migrations:rebase` from the live release. It checks that every global migration was executed and that the schema matches the mapping, then swaps the 108 version rows for the baselines in one transaction. No table changes; on any other state it refuses and changes nothing.
  5. `bin/console doctrine:migrations:up-to-date` must report nothing pending.
- A new installation migrates as before, with `bin/console doctrine:migrations:migrate`.
- Breaking for modules: a migration lives in `src/<Module>/migrations/`, namespace `Foresi\<Module>\Migrations`, and is ordered by its timestamp, then by the module graph. `bin/console app:migrations:owners` checks that every table is created by one installed module.

### 0.8

#### [0.8.0](https://github.com/foresi/foresi/releases/tag/v0.8.0): an instance's tables no longer name the firm (#213)

- **Back up the database before `doctrine:migrations:migrate`.** The migration rewrites 22 tables.
- The migration stops when the database holds more than one firm (`legal_entity`). Such a database must first be split into one instance per firm.
- The tables `firm_invoicing`, `firm_mail_server`, `firm_reminders`, `firm_bank_pairing`, `cash_flow_minimum_balance`, `evidence_series` and `idoklad_connection` get a `one_row` column with a unique index (and a check on PostgreSQL), so each holds at most one row. Scripts that insert into them directly must not add a second row.
- Breaking for anyone who queries the database directly: `legal_entity_id` is gone from the module tables, and some indexes are renamed (for example `idx_invoice_firm_date` is now `idx_invoice_issue_date`). The REST API, MCP and webhook payloads are unchanged; they still return `firm` and `firm_id`.
- `doctrine:migrations:migrate prev` restores the column and gives every row the one firm.

### 0.7

#### [0.7.14](https://github.com/foresi/foresi/releases/tag/v0.7.14): the Taxes and contributions module with the owner profile and its dated statuses (#212)

- Run `bin/console doctrine:migrations:migrate`, which adds the `taxes_owner_profile` and `taxes_status_period` tables.

#### [0.7.0](https://github.com/foresi/foresi/releases/tag/v0.7.0): the API and MCP no longer take a firm; GET /api/v1/firm and foresi_get_firm read the one firm (#196)

- Breaking for API and MCP clients: drop `firm_id` from `GET /api/v1/invoices`, `GET /api/v1/payments`, `GET /api/v1/received-documents`, `GET /api/v1/cash-flow/forecast` and `GET /api/v1/cash-flow/planned-items`, from the bodies of `POST /api/v1/invoices`, `POST /api/v1/cash-flow/planned-items` and `POST /api/v1/received-documents`, and from the MCP tools that took it. A request that still sends it is refused with `400 invalid_parameter` (`parameter: firm_id`); an MCP call with invalid params.
- Breaking: the minimum balance is set with `POST /api/v1/cash-flow/minimum-balance`; `/api/v1/cash-flow/firms/{firmId}/minimum-balance` is gone.
- Breaking: `foresi_list_firms` is gone; use `foresi_get_firm` or `GET /api/v1/firm`.

### 0.6

#### [0.6.0](https://github.com/foresi/foresi/releases/tag/v0.6.0): a contact has one set of roles, invoicing defaults, e-mails and archive state (#195)

- Run `bin/console doctrine:migrations:migrate`. Values a firm had of its own for a contact become the contact's values, and the firm's own invoicing e-mails replace the contact's other invoicing e-mails. Take a backup first: going down does not bring the per-firm values back.

### 0.5

#### [0.5.26](https://github.com/foresi/foresi/releases/tag/v0.5.26): a deleted label goes to the trash for 30 days and comes back onto the same tasks (#193)

- Run `bin/console doctrine:migrations:migrate`. Going down is refused while labels are in the trash.

#### [0.5.25](https://github.com/foresi/foresi/releases/tag/v0.5.25): a recurring invoice that never issued goes to the trash, comes back paused and is purged after 30 days (#192)

- Run `bin/console doctrine:migrations:migrate`.

#### [0.5.24](https://github.com/foresi/foresi/releases/tag/v0.5.24): a deleted file goes to the trash for 30 days, comes back with Undo or from the deleted files of its task, then leaves the disk (#191)

- Run `bin/console doctrine:migrations:migrate`.

#### [0.5.19](https://github.com/foresi/foresi/releases/tag/v0.5.19): contacts filter by every attribute of their catalogue, with text and multi-enum operators (#183)

- For modules: `AttributeType` and `FieldType` gain the case `MultiEnum`, so an exhaustive `match` on them needs an arm. `Attribute` takes `filterable`, `filter`, `within` and `tones`; `Party\Application\View\ContactLists` is a service now (`list()` is no longer static). The DQL function `CAST_TEXT` is registered by Shared instead of Party.

#### [0.5.15](https://github.com/foresi/foresi/releases/tag/v0.5.15): deleted time goes to the trash and can be restored (#159)

- Run `bin/console doctrine:migrations:migrate`, which adds `deleted_at` and `deleted_by` to logged time.

#### [0.5.9](https://github.com/foresi/foresi/releases/tag/v0.5.9): a deleted comment keeps its text for 30 days and can be restored (#161)

- Run `bin/console doctrine:migrations:migrate`.

#### [0.5.0](https://github.com/foresi/foresi/releases/tag/v0.5.0) (from the changelog)

- Breaking for modules: Ui publishes its extension points in `Foresi\Ui\Contract` ([ADR 0038](adr/0038-core-screens-in-integrations.md)). The navigation (`NavigationItem`, `NavigationArea`, their providers, `NavigationCountProvider`, `CoreNavigationAreas`) moves to `Foresi\Ui\Contract\Navigation`, the slots (`SlotItem`, `SlotProvider`, and the slot names in `Slots`) to `Contract\Slot`, the dashboard sections to `Contract\Dashboard`, the shell notices to `Contract\Notice`, `DialogRequest` to `Contract\Dialog`, and `ListDisplay`, `ResolvedColumn`, `WorkingState` and the `WorkingDisplay` interface to `Foresi\Ui\Contract`. `DataTable::OPENS_*` become `Foresi\Ui\Contract\Opens::SHEET`, `DIALOG` and `PAGE`, and the tags of the providers are constants of their interfaces. Update the imports of a module that uses them.
- Breaking for templates: Ui owns its templates. The components, the layouts, `base.html.twig` and the development catalogue move from the application's `templates/` into `src/Ui/templates/`, under the namespace `@Ui`: a screen extends `@Ui/layout/app.html.twig` instead of `layout/app.html.twig`, and `Foresi\Ui\UiBundle` maps the `Ui:` components itself, so `config/packages/twig_component.yaml` no longer names them.
- Saved views: run `bin/console doctrine:migrations:migrate`, which adds the `saved_view` and `saved_view_user_state` tables, and then `bin/console app:views:sync` on every deploy and install. The command stores the views the installed modules ship, such as the tabs of the invoice lists, and leaves alone a view someone edited or hid; running it again changes nothing. Until it runs, the lists show their default views as declared.
- Breaking: the modules move from the PHP namespace `App\` to `Foresi\` (`App\Work\` is now `Foresi\Work\`, the kernel `Foresi\Kernel`, the tests `Foresi\Tests\`), before the first release, as [ADR 0034](adr/0034-module-packages.md) decided. Code that uses a module class must change its imports, and `KERNEL_CLASS` in `.env.test` becomes `Foresi\Kernel`. Stop the workers and let the queues drain before you deploy: a message still waiting names the old classes and cannot be read afterwards, so it goes to the failed transport. Then run `composer install` and `bin/console doctrine:migrations:migrate`, which renames the class names stored in the audit log. Signed-in users sign in again.
- Breaking for modules: ports that another module serves collect their providers by tag instead of an alias in `config/services.yaml`, so a module runs when the one behind its port is absent ([ADR 0034](adr/0034-module-packages.md), rule 3). `Foresi\Work\Application\InvoiceNumbers` moves to `Foresi\Work\Contract\InvoiceNumbers` and `Foresi\Party\Application\BankAccountUsage` to `Foresi\Party\Contract\BankAccountUsage`. A class that implements one of them, or `BilledWork`, `BilledWorkRelease`, `PaymentSourceRelease`, `ZoneFallback`, `CompanyRegistry` or `VatPayerReliability`, is now one provider among others: it is tagged by autoconfiguration and needs no alias.
- Run `bin/console doctrine:migrations:migrate`. The new tables hold series, number counters, invoices, their lines and the numbers an edit gave up.
- Install the PHP extensions `gd` (the QR code and images), `dom` (the ISDOC file) and `zip` (the bulk download), which are now required, and run `composer install` to get the new PDF library, `tecnickcom/tc-lib-pdf` (LGPL-3.0-or-later). The PDF fonts (Inter, SIL Open Font License) are part of the repository.
- Images of a firm are kept in the `firm-images` directory of the application's share directory (`var/share/<env>/firm-images`), set by the new parameter `app.firm_image_dir`. They are outside the web root and outside the database, so include the directory in backups.
- SQLite must be 3.35 or newer.
- Sending by e-mail adds `symfony/mailer` and `symfony/scheduler`, and requires the PHP extension `sodium`. Set `MAIL_SECRET_KEYS` (keys that seal the firms' mail server passwords) and `DEFAULT_URI` (the public https address that links in mail lead to). See [docs/sending.md](sending.md).
- The worker command is now `messenger:consume scheduler_foresi mail async registry --memory-limit=256M --time-limit=3600`: it delivers mail and runs the timed jobs. Update your systemd or supervisor unit.
- PDFs as sent are kept in `var/share/<env>/sent-documents`. Include that directory in backups, together with the database.
- Recurring invoices and reminders run in the same worker, from the `scheduler_foresi` transport; no new process is needed. Their day follows the organisation's time zone, by default the zone of its first active firm's country. Set another with `APP_TIMEZONE` (see below).
- The tests of the PDF and of the ISDOC file use veraPDF, Poppler and the ISDOC schema when they are installed (see CONTRIBUTING.md); CI requires them.
- A firm's bank accounts move from the list on the firm into records of their own. The migration keeps their order and makes the first one the primary account. The list held no currency, so each account gets the home currency of the firm's country: CZK in Czechia, EUR in a euro-area country and USD in the United States. A firm in any other country gets CZK. Check the currency of each account in the firm's bank settings, and correct it for an account kept in another currency.
- Breaking for country modules: the public interface `InvoicingCountry` has a new method, `settlementLimit(Currency $currency): ?int`, the most minor units a firm of the country may write off on an invoice in the currency, or null for none. A third-party country module must implement it; return null to keep small differences unsettled.
- Payments and bank pairing add `zbateson/mail-mime-parser` (BSD-2-Clause), which reads bank e-mail, and `directorytree/imapengine` (MIT), which polls the bank inbox; run `composer install`. The worker command is unchanged.
- Bank e-mail is off until it is set up. The new environment variables are all empty by default: `BANK_INBOX_ADDRESS` (the address template with `{token}`, such as `bank+{token}@example.com`; the mailbox must deliver plus addresses), `BANK_INBOX_TRUSTED_AUTHSERV` (the authserv-id of the inbox's own receiving server, which must add `Authentication-Results` at the top of every message), `BANK_INBOX_TRUSTED_ARC` (trusted ARC sealers), `BANK_INBOX_HOST`, `BANK_INBOX_PORT` (993), `BANK_INBOX_ENCRYPTION` (`ssl` or `starttls`), `BANK_INBOX_USER`, `BANK_INBOX_PASSWORD`, `BANK_INBOX_FOLDER` (`INBOX`) and `INBOUND_WEBHOOK_SECRET` (the bearer secret of the optional webhook `POST /inbound/mail`). Inbound addresses are sealed with `MAIL_SECRET_KEYS`. See [docs/bank-pairing.md](bank-pairing.md).
- Raw bank e-mails are kept in `var/share/<env>/inbound-mail`, as the evidence of the payments recorded from them. Include that directory in backups, together with the database.
- Advances add columns to `invoice`, `invoice_line` and `firm_reminders`; run the migrations. Every stored document stays an invoice. A firm gets the standard series of advance invoices (`Z9{YYYY}{NNNN}`) and of tax documents on payment (`D8{YYYY}{NNNN}`) on first use; set up others under the firm's invoicing settings before the first advance if you number differently.
- Corrective tax documents add columns to `invoice` and `payment`; run the migrations. Every stored payment is incoming. A firm gets the standard series of corrective tax documents (`O7{YYYY}{NNNN}`) on first use.
- The export state adds the columns `exported_at` and `changed_after_export_at` to `invoice`, and the month filter an index on a firm's documents by tax point; run the migrations. Every stored document starts as not exported, so the first pack of a month takes all its documents.
- Projects and tasks add the tables `project`, `project_status`, `project_section`, `task`, `task_watcher`, `task_label` and `label`; run the migrations.
- Received documents add the tables `received_document`, `evidence_series`, `evidence_counter` and `exchange_rate`; run the migrations. Original files are attachments in the `attachments` directory of the share directory, which the backups already include. The worker must reach `www.cnb.cz` to fetch the ČNB rates daily (the job `exchange-rates`, after 14:35 Prague time); without it a rate has to be typed by hand. See [docs/operations.md](operations.md#exchange-rates) and [docs/purchasing.md](purchasing.md).
- Comments and attachments add the tables `task_comment`, `comment_mention` and `attachment`; run the migrations. Attached files are kept in `var/share/<env>/attachments`, outside the web root and the database: include that directory in backups, together with the database (`ops/backup.sh` already covers the share directory).
- People add the table `invitation` and the column `disabled_at` to `membership`; run the migrations. Every existing membership stays active. Invitations are mailed through the system mail (`SYSTEM_MAILER_DSN`, `SYSTEM_MAIL_FROM`); without it the owner passes the link on.
- The roles are now owner, team member and guest: the migrations turn every client and subcontractor membership into a guest one, who sees only what is shared with them. `app:user:create` takes `owner`, `member` or `guest`, a guest with `--contact=<id>`.
- The move from iDoklad adds tables for the iDoklad keys, the downloaded snapshot, the jobs, the links of imported records and the reports of checks and imports, and columns for the import source, run and original PDF of documents and payments; run the migrations. Its PDFs are kept in `var/share/<env>/idoklad-documents` and the originals of imported documents in `var/share/<env>/original-documents`; include both directories in backups. The jobs run on the `async` transport of the existing worker, and the iDoklad keys are sealed with `MAIL_SECRET_KEYS`. See [docs/idoklad.md](idoklad.md).
- System mail of the installation, such as invitations, goes through its own mailer, never a firm's server. Set `SYSTEM_MAILER_DSN` and `SYSTEM_MAIL_FROM` (such as `Foresi <noreply@example.com>`) and check them with `bin/console app:ops:test-mail <to>`. The mail is queued on the existing `mail` transport, so the worker command is unchanged. While either is empty, `/health` reports the new check `system_mail` as degraded. See [docs/operations.md](operations.md#environment-variables).
- The REST API and the MCP server add the table for personal API tokens and an `api_token_id` column on audit entries; run the migrations. The MCP endpoint at `/mcp` answers only for the hosts in the new `MCP_ALLOWED_HOSTS` (default `localhost,127.0.0.1`): set it to the public host, such as `app.example.com`. Streamable HTTP sessions are kept in `var/share/<env>/mcp-sessions`, which works on one machine only. See [docs/api.md](api.md), [docs/mcp.md](mcp.md) and [docs/operations.md](operations.md#environment-variables).
- Every module directory under `src/` now has its own `composer.json` manifest and is installed as a Composer path package (`vendor/foresi/<id>` links to `src/<Module>`). Run `composer install` from a full checkout; nothing else changes at runtime. See [docs/adr/0029-module-registry.md](adr/0029-module-registry.md).
- API writes add the table `api_idempotency_key`; run the migrations. The worker prunes keys older than 24 hours every hour, so the worker command is unchanged. `app:api-token:issue` now requires `--scope`, such as `--scope=read` or `--scope=contacts:write`: update scripts that issue tokens without it. Tokens issued before keep their scopes, and a `write` token now also reads. See [docs/adr/0032-api-writes.md](adr/0032-api-writes.md).
- Cash flow adds the tables `cash_flow_planned_item`, `cash_flow_checkpoint`, `cash_flow_expected_date` and `cash_flow_minimum_balance`; run the migrations. API tokens need the new scope `cashflow:read` or `cashflow:write` to use it.
- Payments of received documents add the table `received_document_payment`; run the migrations. Every stored received document starts with no payment, so a recorded one shows as unpaid until its payments are recorded.
- One instance per customer ([ADR 0035](adr/0035-one-instance-per-customer.md)) starts: the core module Tenancy is now `foresi/instance` (`App\Instance\`); run `composer install`. A new migration stops the upgrade when the database holds more than one tenant: move each tenant but one into an instance of its own first. A fresh database passes. Stop the workers before the deploy (the deploy script does) so no queued message names an old `App\Tenancy\` class.
- The time zone of the instance is the new environment variable `APP_TIMEZONE`, a canonical name of the tz database such as `Europe/Prague`; a wrong one stops the instance at start. Empty (the default) changes nothing: a zone stored with `app:tenant:timezone` still wins, then the country of the first active firm, then UTC. If you set a zone with `app:tenant:timezone`, put the same one in `APP_TIMEZONE`: the stored zone goes away in a later release. See [docs/operations.md](operations.md).
- The tables of cash flow, purchasing, the timesheet and the iDoklad move lose their tenant column; their unique and lookup indexes keep their names on the remaining columns. Take a backup before you migrate: going back down works only while these tables are empty. New iDoklad PDFs are kept directly in the iDoklad files directory, no longer in a subdirectory per tenant; files already pulled stay where they are and still read.
- The tables of the webhooks, the event log, the audit log and the attachments lose their tenant column; the indexes that led with it keep their names on the remaining columns, except `idx_webhook_endpoint_tenant_active`, now `idx_webhook_endpoint_active`, and `idx_event_log_tenant_position`, which goes. Take a backup before you migrate: going back down works only while these tables are empty. New attachments are kept directly in the attachments directory, no longer in a subdirectory per tenant; files already uploaded stay where they are and still read. The audit log no longer needs an active tenant to be written.
- The tables of banking and work lose their tenant column the same way, with the same backup advice. New raw bank mail is kept directly in the inbound mail directory, no longer in a subdirectory per tenant; mail already received stays where it is and still reads.
- The tables of invoicing lose their tenant column the same way, with the same backup advice. Sent and original PDFs are now stored as `{id}.pdf` in the directory itself, no longer in a subdirectory per tenant; those stored before are kept where they are and still read.
- The tables of the contacts and firms, the people, invitations and API tokens, and the imports lose their tenant column, and so does the primary key of `api_idempotency_key`; the indexes that led with it keep their names on the remaining columns, except `uniq_membership_tenant_user`, now `uniq_membership_user`: a person has one membership. Take a backup before you migrate: going back down works only while these tables are empty.
- The organisation switcher is gone (`/organisation/choose`, `/organisation/switch/{tenantId}` and the user menu item): a signed-in person works in the one organisation of the instance.
- One firm per instance ([ADR 0039](adr/0039-one-firm-per-instance.md)) starts: a new migration stops the upgrade when the database holds more than one firm (legal entity), archived ones included. Move each firm but one into an instance of its own first. A fresh database passes. A firm can now be created only when the instance has none (the first-run setup); the new-firm screen sends you back to the firm list otherwise. The time zone without `APP_TIMEZONE` and the name of the organisation follow that firm.
- **Breaking:** `GET /api/v1/me` and the MCP tool `foresi_whoami` no longer return `tenant`; the response has `user` and `token` only. Clients that read `tenant.id` or `tenant.name` must stop doing so.
- **Breaking for operators:** `app:api-token:issue` and `app:api-token:list` no longer take `--tenant`, and the list has no organisation column; `app:user:create` takes the e-mail and the role only (`app:user:create owner@example.com owner`), no organisation name. Update scripts that pass them.
- The last step of one instance per customer: the `tenant` table and the tenant column of `scheduled_job_run` go, so no table has a tenant column any more; each timed job now has one row and runs once per period. Take a backup before you migrate: going back down works only while `scheduled_job_run` is empty. The organisation's name shown in the app and in mail is now that of the first active firm (before the first firm, "Foresi"); the time zone is `APP_TIMEZONE`, else the first active firm's country, else UTC.
- **Breaking for operators:** `app:tenant:timezone` is removed. A zone stored with it is not carried over: if you set one, put it in `APP_TIMEZONE` before you migrate.

### 0.4

#### [0.4.0](https://github.com/foresi/foresi/releases/tag/v0.4.0) (from the changelog)

1. Finish or cancel imports that are in progress, because their queued work cannot be read after the upgrade. Then stop the web server and cron (`app:import:health`, `app:import:purge`).
2. Let the workers drain the `async` and `registry` queues, until `bin/console messenger:stats` shows those two empty. The `failed` queue may hold messages. A message left in `async` or `registry` cannot be read afterwards; it is moved to `failed`, not lost, but its work has to be started again.
3. Stop the workers.
4. Back up the database and the `var/imports` directory.
5. Deploy the code and clear the cache (`bin/console cache:clear`).
6. Run the migrations with `bin/console doctrine:migrations:migrate`. The identifier migration cannot be reverted and cannot be rehearsed with `--dry-run` or `--write-sql`; the application refuses both. Run it through `bin/console`, not through a standalone `doctrine-migrations` binary, which does not have that protection.
7. Start the web server, cron and the workers.

Signed-in users stay signed in: a session from before the upgrade still loads, the user is refreshed from the database by e-mail address, and the organisation chosen in it stays chosen, because organisation identifiers do not change. Old URLs stop working (see below).
