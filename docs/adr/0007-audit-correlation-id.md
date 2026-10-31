# 7. Audit correlation id

* Status: accepted
* Date: 2026-10-30

## Context and problem statement

Some operations change many records at once: an import creates and updates hundreds of rows, and undoing it must find exactly those changes again. Future bulk operations will need the same. The audit log (see [5](0005-append-only-audit-log.md)) records each change on its own, so entries of one operation cannot be told apart from unrelated edits.

## Decision drivers

* Entries of one unit of work can be found together.
* The append-only rule stays unchanged.
* Features opt in without each write remembering to pass an id.

## Considered options

* An optional correlation id on every audit entry, set from a scoped service
* A separate table linking operations to audit entries
* Features keeping their own list of the entities they touched

## Decision outcome

Chosen option: "an optional correlation id on every entry", because it needs one nullable column, works the same on every supported database and keeps the listener the only writer of the log.

### Rules

* `AuditEntry` has a nullable `correlation_id` (up to 64 characters), indexed together with the tenant.
* `AuditCorrelation::within($id, $work)` makes `$id` the current correlation for the work; the listener stamps it on every entry it writes meanwhile, for creations, updates and removals alike. Outside it, entries carry none.
* Nesting the same id is allowed; nesting a different id throws. The previous value is restored when the work ends, also by an exception, and the service is reset between messages of a worker.
* Ids are built as `<operation>:<id>`: `import:<importId>` for an import and `import-undo:<importId>` for its undo.
* `AuditTrail::changedSince()` answers whether an entity has a newer entry that belongs to no given correlation. The import undo asks the target (`ImportTarget::changedSince()`), which checks the record and everything that belongs to it the same way; for contacts `DoctrinePartyChanges` does so for the party and its child entities.

### Consequences

* Good: related changes are queryable together; entries stay immutable.
* Good: existing entries need no backfill, their id is null.
* Bad: the id is a convention of the caller; nothing checks that it names a real operation.
