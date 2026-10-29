# 5. Append-only audit log

* Status: accepted
* Date: 2026-10-29

## Context and problem statement

Business records need a trustworthy history: who changed what and when. A history that can be edited afterwards is of little value.

## Decision drivers

* Changes are recorded without each feature remembering to do it.
* Entries cannot be altered or removed through the application.
* The log respects tenant isolation.

## Considered options

* A Doctrine listener writing audit entries for entities marked as audited
* Database triggers
* Event sourcing

## Decision outcome

Chosen option: "a Doctrine listener", because it works the same on every supported database, stays testable in PHP and does not require a change of the whole persistence model.

### Rules

* An entity is audited when it implements the `Audited` marker interface. Properties can be excluded with the `AuditExcluded` attribute.
* On flush, the listener records creation, change and deletion as `AuditEntry` rows with the acting user, a timestamp and the changed values.
* `AuditEntry` is tenant-owned and takes the tenant of the audited entity.
* `AuditEntry` has no mutators, and the listener rejects any update or removal of a persisted entry at flush. Changes to audited collections are rejected rather than silently missed.
* Values are normalised to plain scalars and arrays before they are stored.

### Consequences

* Good: auditing is declarative and consistent.
* Good: entries are append-only as far as the application is concerned.
* Bad: changes made outside the ORM (raw SQL, manual database edits) are not recorded. Protecting the database itself, for example with restricted privileges, is an operational matter.
* Bad: the log grows without bound until an archiving policy exists.
* Bad: audited values can contain personal data, and rows are append-only, so a person's data cannot be erased from the log through the application. An erasure or pseudonymisation strategy is an open question and must be settled before the system is used to process personal data of data subjects who can ask for erasure.
