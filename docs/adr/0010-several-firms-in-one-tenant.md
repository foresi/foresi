# 10. Several firms in one tenant

* Status: accepted
* Date: 2026-11-01

## Context and problem statement

A tenant is the unit of data isolation and of membership: the people who work in it. One organisation, however, may operate several legal entities (firms) that issue documents under their own names and registration numbers while sharing staff, customers and suppliers. Without a decision, either every firm becomes a tenant, which duplicates contacts and forces users to switch constantly, or the model silently assumes one firm per tenant, which is hard to undo later.

## Decision drivers

* Contacts and users are shared by the firms of one organisation; entering them twice is a cost with no benefit.
* A document must always be issued by exactly one firm, never by a guess.
* Data that belongs to one firm must not end up in shared records, so that a firm can later be separated.
* Keep the tenant boundary simple and enforced in one place (ADR 3).

## Considered options

* One tenant, several firms (`LegalEntity` records inside the tenant)
* One tenant per firm, with shared data copied or linked across tenants
* One firm per tenant, no support for groups

## Decision outcome

Chosen option: "one tenant, several firms", because users and contacts are shared naturally and the tenant filter stays the only isolation mechanism.

### Rules

* A tenant owns one or more firms. A firm has its own legal name, registration number, VAT ID, address and bank accounts.
* Shared data is stored once per tenant: users and memberships, contacts and their links.
* Data that depends on the issuing firm is stored per firm, keyed by the contact and the firm. Contact settings such as the due period, currency, document language, payment method and reverse charge are an example (`PartyFirmSettings`). A firm without settings for a contact falls back to the contact's own defaults.
* The issuer of a document is chosen explicitly. It is never derived from a default or from the order of the firms. With a single active firm the choice is made for the user and is still stored on the document.
* There is no way to act as several firms at once: lists and reports for the active firms are not merged by a multi-select. Each action concerns one firm.
* A registration number is unique among the active firms of a tenant within a country. The rule is stored as a nullable `active_registration_key` column in a unique index: it is set while the firm is active and cleared when it is archived, and a unique index ignores nulls on every supported database, so an archived firm does not block re-adding the number.
* A firm is archived, not deleted, because documents it issued refer to it. There is no route to restore an archived firm; adding the same firm again creates a new record.
* Separating a firm into its own tenant is possible future work and is not built. To keep it possible, shared records must hold no per-firm data, and per-firm data must always name its firm.

### Consequences

* Good: one set of contacts and users for a group of firms.
* Good: the issuer is always explicit, which is what a document needs legally.
* Bad: every feature that depends on the issuer must be designed with the firm in mind and cannot assume a single one.
* Bad: a group that needs strict separation between firms (different staff, confidential customers) must use separate tenants. Row-level tenancy does not isolate firms from each other inside a tenant.
* Bad: separating a firm later requires a data migration that copies or splits shared records.
