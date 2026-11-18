# 39. One firm per instance

* Status: accepted
* Date: 2026-11-18
* Supersedes: [ADR 0010](0010-several-firms-in-one-tenant.md), and the firm parts of [ADR 0035](0035-one-instance-per-customer.md)

## Context and problem statement

ADR 0010 put several firms (legal entities) into one tenant, so that the firms of one group share users and contacts, and every contact can carry settings of its own per firm. ADR 0035 replaced tenants with one instance per customer and kept the firms: an instance holds one or more firms.

With an instance per customer, a second firm of the same group can just as well be an instance of its own. Keeping several firms in one instance costs every module that touches the issuer: a firm picker on every form and list, a `firm_id` on API and MCP reads, per-firm contact settings that the address book must merge, and tests for every combination.

Measured on 2026-11-18:

* The live instance holds 1 legal entity, 1 invoice, 1 payment, 1 number series, 1 bank account, 1 mail server, 0 per-firm contact settings and 0 contact e-mails scoped to a firm. Nothing real depends on several firms.
* In the code, about 185 of 1538 source files touch firms: 22 entities have a `legal_entity_id`, 24 of 85 migrations and 228 of 536 test files name firms. About 70 % of that is the company's own identity and settings, which stay.
* Prior art: Fakturoid, QuickBooks Online and Xero keep one company per account, with a switcher at sign-in on top and fully separate data; iDoklad separates data per firm. Odoo's shared-database multi-company is where its cross-company bugs and complaints sit.

## Decision drivers

* One instance per customer (ADR 0035) already isolates data by deployment; a second boundary inside the instance repeats it.
* A new module should not have to learn a firm picker, a firm filter and per-firm settings.
* The baselines of ADR 0034 should not carry multi-firm.
* An upgrade must stop, not merge, when a database is not in the expected state.

## Considered options

* Keep several firms per instance (ADR 0010 as it stands)
* One firm per instance, several firms removed (chosen)
* Keep the data model for several firms and hide it in the screens

## Decision outcome

Chosen option: "one firm per instance". An instance holds exactly one firm, the legal entity that issues its documents and names the organisation. Several firms of a group are several instances, like several customers. Sharing users across instances comes later with single sign-on and an instance switcher; there are no shared tables.

Hiding the model in the screens was rejected: it keeps code that nothing uses, and modules written for customers would learn to depend on it.

### Rules

* `InstanceFirm` in Party is the one way to read the firm: `find()` (null before the first-run setup) and `get()`. Fallbacks that used "the first active firm", such as the time zone without `APP_TIMEZONE` and the name of the organisation, read it.
* A firm is created only when the instance has none, archived ones included: the first-run setup. `InstanceFirm::create()` refuses another with `FirmAlreadyExists`, and the new-firm screen sends the user back to the firm list.
* A guard migration stops an upgrade when `legal_entity` holds more than one row, archived ones included. Such a database must first be split into instances; no migration merges firms.
* What belongs to the company stays as it is: its identity (name, registration number, VAT ID, address, register entry, logo, stamp, VAT status), number series, bank accounts and feeds, invoicing and mail settings, reminders, evidence series, the iDoklad connection, and the issuer frozen on each issued document (ADR 0010 on freezing still applies).
* A document still names its issuer and freezes it when it is issued; with one firm there is nothing to choose.

### Removal

Several firms go in steps, each its own change: the per-firm contact settings fold into the contact's own; firm pickers, firm parameters and list filters go from the screens; the `firm_id` filters and the list of firms go from the API and MCP (a breaking change with a changelog entry); then the firm list, archive and new-firm screens become one company settings page (this screen step came first, on 2026-11-18: Settings › Firm at `/settings/firm`). Whether the `legal_entity_id` columns are dropped or kept as a foreign key to the one firm is decided with the last step, before the baselines.

### Consequences

* Good, because no form, list, query or API call has to choose or filter a firm.
* Good, because a contact has one set of settings, and the address book has nothing to merge.
* Good, because the baselines of ADR 0034 are simpler.
* Bad, because a group with several firms runs several instances, and a person working for all of them signs in to each until single sign-on exists.
* Bad, because a contact shared by the firms of a group is entered in each instance.
* Bad, because API and MCP clients that pass `firm_id` must change.
