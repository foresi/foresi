# 11. Audit roots and retention

* Status: accepted
* Date: 2026-11-01

## Context and problem statement

The audit log (ADR 5) records every change as an entry about one entity. A document or a contact, however, consists of several entities: lines, addresses, e-mail addresses, bank accounts and links. A reader wants the whole history of a document or a contact in one query, including parts removed since. Separately, the log is append-only while personal data in it may have to be erased or reduced, and its entries may have to be kept for as long as the related business document must be kept. Both questions need a recorded position.

## Decision drivers

* One indexed query returns the complete history of a document or contact.
* Children that were removed remain part of the history of their parent.
* Entries stay append-only for the application.
* Retention follows the legal period of the related document and is not decided per feature.
* Personal data is not kept longer than needed.

## Considered options

* Record the aggregate root on every entry
* Reconstruct the history by joining through the current relations
* Keep one log per entity and merge in the reader

## Decision outcome

Chosen option: "record the aggregate root on every entry", because it needs no joins and survives the removal of a child. The retention position below is a decision about intent, not a feature.

### Roots

* Every `AuditEntry` stores `root_class` and `root_id`, indexed together with the tenant.
* An entity that belongs to another implements `AuditRooted` and names its root. An audited entity without it is its own root.
* All entries, including those for objects created in the same flush as their root, are created while the unit of work is flushed, so the root is known and the entry is written in the same transaction as the change.
* The history of a root is a single query on tenant, `root_class` and `root_id`.

### The `ContactLink` limit

A contact link joins two parties: a company and a person. It has one root, and that is the company. The consequences are accepted:

* A link that was removed appears in the history of the company only, not in the history of the person.
* A reader of a person's history also reads the entries of the person's current links, in a second query, and merges the two. Links removed earlier are not found that way.
* The same limit affects undo of an import. After a link has been removed, undo of the person's row does not report it, because nothing under the person's root records it.

A link remains in the history of the company, where the audit of the relationship is complete. If a feature needs the removal under the person as well, a second entry or a second root has to be designed; this is not done now.

### Retention

This position is subject to professional tax and legal review and is not legal advice.

* Audit data is kept for at least as long as the retention period of the related document runs (for an issued invoice, the period required by the applicable tax and accounting law). The application never deletes audit entries during that period.
* After that period, the personal values of a person in the log (names, addresses, e-mail addresses, phone numbers, bank accounts) may be pseudonymised. The entries themselves, with their time, actor, entity and kind of change, remain.
* This is an operator procedure on the database, for example a documented script run with the necessary privileges. The application has no feature for it yet, and entries remain append-only as far as the application is concerned.
* A request for erasure of a person's data is answered by pseudonymisation after the retention period and by refusing erasure within it where the law requires keeping the record. Handling such requests is the responsibility of the operator of the installation.

### Consequences

* Good: the complete history of a document or contact is one query, children removed since included.
* Good: the retention position is written down before a feature depends on it.
* Bad: a contact link is seen from one side only (see above).
* Bad: until the operator procedure exists, personal data in the log cannot be reduced by the application, and the log grows without bound.
* Bad: the retention periods differ by country and document type and need review before the system issues documents.
