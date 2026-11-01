# 9. UUIDv7 identifiers

* Status: accepted
* Date: 2026-11-01

## Context and problem statement

Entities were identified by database-generated whole numbers. Such keys are guessable, differ between installations, leak how many records exist, and cannot be assigned before the row is stored. Business documents will need identifiers that are stable for as long as the document is kept, and data will be moved between installations and exchanged with other systems.

## Decision drivers

* An identifier can be created in the application, before the first write, and never changes.
* Identifiers are unique across tables and installations, so data can be moved or merged without renumbering.
* New identifiers sort roughly by creation time, so index inserts stay local and an order by id is meaningful.
* One storage type that behaves the same on SQLite and PostgreSQL.

## Considered options

* UUIDv7
* ULID
* Whole numbers from the database (the previous state)
* UUIDv4

## Decision outcome

Chosen option: "UUIDv7", because it is a standard (RFC 9562) with first-class support in the Symfony Uid component, PostgreSQL clients and most other tools, and it is time-ordered. A ULID has the same ordering but is not a standard UUID and is stored in the same 128 bits anyway. Whole numbers cannot be assigned before the write and are not unique across installations. UUIDv4 is random, so it spreads inserts over the whole index.

### Rules

* Every entity, including the tenant, is identified by a UUIDv7, generated in the application (`App\Shared\Domain\Id`) when the object is created.
* The column type is Doctrine `guid`: a native `uuid` column on PostgreSQL and a string on SQLite. Identifiers are written in the canonical lower-case form.
* A foreign key holds the same type as the key it points to.
* A document number (an invoice number, for example) is a business attribute that follows legal and numbering-series rules. It is never a key and never used to look up a record by reference from another table.
* Identifiers in URLs, exports and imports are the UUID. Old numeric URLs and an `id` column of a file exported before the change do not match any record (see the changelog and `docs/import.md`).
* A migration converts existing data. It keeps the order of the old keys, rewrites references, the audit log and import snapshots, and cannot be reverted. It does its work while it runs, so it cannot be rehearsed with `--dry-run` or `--write-sql`: the application refuses both for it through the `RunsOnlyForReal` marker and the `RefuseMigrationRehearsal` listener. A standalone `vendor/bin/doctrine-migrations` run does not load the application and is not covered by that protection, so always use `bin/console doctrine:migrations:migrate`.
* Messages queued before the upgrade cannot be decoded after it. `KeepUndecodableMessages` sends them to the failed transport instead of discarding them. Queues are drained before the upgrade so that this does not happen.

### Consequences

* Good: identifiers can be created offline and are safe to expose.
* Good: no information about the number of records leaks through identifiers.
* Bad: identifiers are longer than numbers, which makes keys and indexes larger and URLs less readable.
* Bad: the one-time migration rewrites every table, is irreversible and needs a backup and a maintenance window.
* Bad: a UUIDv7 contains its creation time in the first 48 bits. This is accepted; identifiers are not secrets and access is always checked.
