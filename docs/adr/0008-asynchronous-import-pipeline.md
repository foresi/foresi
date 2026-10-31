# 8. Asynchronous import pipeline

* Status: accepted
* Date: 2026-10-31

## Context and problem statement

An import reads a file of up to 200,000 rows, checks every row against the data already stored and writes the result into a module's tables. This takes minutes, must survive a restart of the server and must be safe when the same message is delivered twice. Imports of one tenant into one target (for example contacts) must not run at the same time, and a self-hosted installation may have SQLite and a single worker or PostgreSQL and several.

## Decision drivers

* A web request never waits for bulk work, and a closed browser tab does not stop it.
* A crash loses at most one chunk, never the whole import, and no row is written twice.
* The same code runs on SQLite (one writer) and PostgreSQL (parallel workers).
* No new infrastructure beyond what Foresi already needs (database and Symfony Messenger).

## Considered options

* One Messenger message per chunk of 500 staged rows, with the state and the counters in the database
* One message per import that loops over all rows
* A dedicated job runner or queue service

## Decision outcome

Chosen option: "one message per chunk", because a failed or redelivered chunk is retried alone and parallel workers on PostgreSQL share the work without coordination.

### Rules

* The upload is stored privately per tenant. A worker reads it once into staged rows (`import_row`), 500 per chunk, with batched inserts; later steps work on the staged rows, never on the file.
* Reading, checking, running, archiving the records missing from a full replace, and undoing are Messenger messages (`ReadImportFile`, `CheckImportChunk`, `RunImportChunk`, `ArchiveMissing`, `UndoImportChunk`). Every message carries the tenant and is handled inside it.
* Handlers are idempotent. A chunk marks each row when it has written it and skips marked rows, so a message delivered again changes nothing. Counters are added under a row lock (`SELECT ... FOR UPDATE`).
* Each row is written in its own savepoint under the audit correlation `import:<id>` (see [7](0007-audit-correlation-id.md)). A rejected row becomes an error and the chunk goes on.
* Only one import per tenant and target is Running or Undoing. The others wait in Queued. The state in the database decides, and a named lock (`LOCK_DSN`, `flock` by default) around each decision keeps two requests or workers from deciding at the same time. When an import ends, the one that has waited longest starts.
* The `async` transport has `redeliver_timeout: 7200`, longer than any handler. An import that has changed neither state nor counters for twice that time is stalled. `app:import:health` marks it Failed with `import.failure.stalled`, frees the target and starts the next queued import. It does not re-send lost chunk messages, because the Doctrine transport cannot tell reliably which chunks of one import are still waiting. A Failed import that had started writing (it reached Running, so it has a start time and counted rows) moves back to Undoing on request, within the same 30 days as any ended import; the undo passes over rows the run never wrote and skips rows an earlier undo already reverted. An import that failed while reading or checking wrote nothing and cannot be undone.
* `app:import:purge` deletes the file and the staged rows 30 days after an import ended. The import record and its counters stay, and undo is possible until then.

### Consequences

* Good: bulk work survives restarts, scales with workers on PostgreSQL and needs nothing beyond the database.
* Good: the web process only stores the upload and starts the work.
* Bad: the default `flock` lock works on a single host only. Several hosts need a shared `LOCK_DSN` (see `docs/import.md`).
* Bad: a crash between committing a decision and sending its messages can leave an import Running with no messages; the health check ends it as Failed after four hours. An undo chunk that cannot revert fails and is retried, so the import can stay in Undoing until the cause is removed or the health check fails it; the undo can then be started again.
