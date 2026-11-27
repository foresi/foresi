# 45. Custom fields declared by modules

* Status: proposed
* Date: 2026-11-25
* Builds on: [ADR 0044](0044-public-extension-contract.md) (the extension contract), [ADR 0033](0033-module-contracts-and-integrations.md), [ADR 0030](0030-domain-events.md), [ADR 0005](0005-append-only-audit-log.md)

## Context and problem statement

Contacts, deals, tasks, projects and products have only the fixed columns of their own module. A firm that needs one more attribute, such as an industry on a contact or a contract end date on a deal, has no way to add it. A module could add its own table and a tab, but the value would not appear in lists, filters, saved views, the REST API, MCP, import and export, document templates, the audit history or events. If each module solved this its own way, the solutions could not be unified later.

Foresi is built for firms that extend it with their own modules, often written with AI. Fields that live in code get types, tests, static analysis and review. Fields defined at runtime need rules for changing types, deleting data and permissions on definitions.

## Decision drivers

* A module adds a field to another module's record without editing that module or the core.
* An added field works everywhere a built-in attribute works: screens, lists, filters, sorts, saved views, REST and OpenAPI, MCP, import and export, templates, audit, events.
* Identical behaviour on PostgreSQL and on SQLite in tests.
* A later way to define fields at runtime must not change any consumer.
* Tags, which users create every day, must not need a code change.

## Considered options

* Who defines fields: modules in code only (chosen); an admin screen and MCP tools now; both now.
* Storage: a module-owned 1:1 extension entity with typed columns (chosen); a JSONB column on the record; a typed value table (entity, field, value); real columns added to the record's table at runtime.
* How a module attaches: an extension interface published by the record's module plus a field attribute (chosen); Doctrine inheritance of the record's entity; a service that lists definitions separately from storage.
* Tags: a separate optional module (chosen); tags in the core; tags as a multi-select field.

Measured on PostgreSQL 17 with 1 000 000 contacts and 11 fields (filter with date range and sort by name / count for a filter / size): JSONB on the record 32 ms / 196 ms / 517 MB; typed value table 236 ms / 6.5 ms / 1094 MB; real typed columns on the record 13 ms / 5.7 ms / 253 MB; typed columns in a 1:1 side table 52 ms / 5.7 ms / 352 MB. The side table needs the list query to take the page of record ids first and join it after: a plain LEFT JOIN with keyset paging took 380 ms for a page of 50, the page-first shape 0.27 ms. Odoo, Frappe and Shopify let modules or apps declare fields in code and keep them apart from fields that admins add; none of the products compared allows a free change of a field's type.

## Decision outcome

### Fields are code

A field exists because a module declares it and ships a migration. No screen or tool creates, changes or deletes a field definition. A builder for runtime fields may come later as a second source behind the same contract.

### Storage and declaration

A module that adds fields to a record owns a Doctrine entity whose id is the record's id, in its own table with one typed column per field. The record's own entity and table are never altered by another module. Doctrine inheritance is not used: its discriminator map would make the record's module know every extending module, and a row can have only one class.

Each record module publishes an extension interface in its contract: `Foresi\Party\Contract\Field\ContactExtension`, `Foresi\Deals\Contract\Field\DealExtension`, `Foresi\Work\Contract\Field\TaskExtension`, `Foresi\Work\Contract\Field\ProjectExtension` and `Foresi\Catalog\Contract\Field\ProductExtension`. The extension entity implements it, and each property that is a field carries the attribute `#[CustomField]` from `Foresi\Shared\Contract\Field` with its kind, label (a translation key), options and flags (`filterable`, `sortable`, `required`, `guests`, `api`, `template`, `import`, `searchable`).

Kinds: text, long text, number, money, date, date-time, boolean, URL, select, multi-select, contact reference, user reference. Select values are stored as option keys.

A field's key is the module's id and the property's name in snake case, such as `acme_fleet_industry`. It never changes and contains no dot. A change of kind is a new field with a new key and a data migration.

### One registry for every consumer

`Foresi\Shared\Contract\Field\FieldSource` (tag `foresi.shared.field_source`) gives the definitions of a record kind, reads and writes values, and gives the DQL expression for filters and sorts. The core's only source reads the extension entities. Consumers ask the registry and never the source, so a later source needs no change in them.

From the registry:

* the record's dialogs and detail show the fields;
* the record's list gets an attribute per field, with filter operators from its kind, so saved views and quick filters work;
* REST payloads carry a `custom_fields` object, the OpenAPI schema is generated from the definitions, and a read-only endpoint lists the definitions;
* the MCP tools of the record read, write and filter `custom_fields`, and `foresi_list_fields` lists the definitions;
* import and export offer the fields;
* templates read them as `contact.custom.<key>` and the like;
* the extension entity is audited under the record, so every change shows in the record's history;
* the record's update event names the changed keys, never the values;
* a field is hidden from guests unless it says otherwise.

### Tags

Tags are a separate optional module, `foresi/tags`. A user creates a tag by typing it, and tags have a colour and can be renamed, merged and trashed. A record kind opts in through `Foresi\Tags\Contract\TagTarget`, as record kinds do for activities. Work's task labels move into it.

### Consequences

* Good, because a field is one property with one attribute and one migration, and it works everywhere at once.
* Good, because typed columns count and page as fast as columns on the record, are smaller than JSONB or a value table, and behave the same on SQLite.
* Bad, because a filter that combines a record column with an extension column is about four times slower than with columns on the record (52 ms against 13 ms at a million contacts), and the registry's list query must use the page-first shape.
* Good, because the registry leaves room for runtime fields without changing consumers.
* Bad, because a firm without a developer or an AI that writes modules cannot add a field until a builder exists.
* Bad, because module authors must follow migration rules for large tables: nullable columns or constant defaults only, and indexes created concurrently.
