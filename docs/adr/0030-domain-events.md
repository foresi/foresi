# 30. Domain events

* Status: accepted
* Date: 2026-11-12

## Context and problem statement

A module that wants to react to a fact in another module has no way to do it. Billing time is locked and released through a port the other module must call, the audit log records what changed but not what it means, and a script or an assistant that wants to know "which invoices were paid since yesterday" has to poll the lists (ADR 0027). ADR 0029 added the module registry and promised public contracts per module; events are the first of them.

What exists today is `AdvanceRefunded`, an internal Application event that one use case dispatches to another inside the same module. It carries entities and is not a contract.

## Decision drivers

* A module announces facts without knowing who listens, and listeners never reach into the publisher.
* An event is part of the public surface: stable names, versions, and no personal data in it.
* An event is recorded only if the change that caused it is committed.
* Delivery survives a crash and a retry, and a listener acts once per event.
* One log serves listeners now and the REST and MCP feed, and later webhooks.
* The code runs on SQLite in tests and PostgreSQL in production, so no database-specific features.

## Considered options

* Where events live: `<Module>/Contract/Event` (chosen); next to the use case; one shared namespace
* Payload: thin, facts only, no personal data (chosen); the whole entity; ids only
* Recording: a table written in the transaction of the change, numbered after commit (chosen); dispatch after commit only; a database sequence as position
* Delivery: Symfony Messenger with the Doctrine transport and a consumption row per listener (chosen); one handler per event on a shared bus
* Listeners: synchronous only within the publishing module, asynchronous across modules (chosen); all synchronous; all asynchronous

## Decision outcome

### Contract and names

An event is a `final readonly` class in `App\<Module>\Contract\Event\`, implements `DomainEventPayload` and carries `#[DomainEvent('<name>', version: N)]`. The name is `module.resource.action`, lower case, matching `^[a-z][a-z0-9-]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`, and its first segment is the id of the module whose namespace holds the class (ADR 0029). The version is a positive integer per name. Within a version a change can only add a fact.

`Contract/` depends on `Shared/Domain` only. The attribute, `DomainEventPayload` and `EventSubject` are in `Shared/Domain/Event`; the ports `EventRecorder`, `EventOutbox`, `DomainEventListener`, `RecordedEvent`, `EventReadAccess` and `EventSubjectUrl` are in `Shared/Application/Event`. `Shared` requires no module, so the types cannot live with the engine.

The engine is the core module `Events` (`src/Events`, id `events`, requiring shared, tenancy and audit). Its `EventCatalogue` reads the `Contract/Event` folder of every installed module (ADR 0029), and `bin/console app:events` lists it; with `--check` it fails on a bad name or prefix, a duplicate, a missing or wrong version, or a fact of a type that is not allowed. CI runs the check.

Invoicing publishes the first events, all version 1: `invoicing.invoice.issued`, `invoicing.invoice.delivered`, `invoicing.payment.recorded`, `invoicing.invoice.paid`, `invoicing.invoice.trashed` and `invoicing.invoice.restored`. `paid` is recomputed after a payment is recorded or cancelled and is recorded when the payment state changes.

### The envelope

The public envelope has snake case keys: `id` (UUIDv7), `type`, `version`, `occurred_at`, `subject` (`type`, `id`, `url`), `data`, `correlation_id` and `actor` (`kind`). `position` is added in the feed only.

The public properties of the class are the facts, and they are thin: `string`, `int`, `bool`, `null`, a backed enum declared in the same `Contract` namespace, or `list<string>`. They are identifiers, codes, amounts as decimal strings with a currency, and ISO dates. A fact is never a name, an address, free text or a note; a reader that needs more follows the subject URL and its own permissions decide. `tenant_id`, user ids and API token ids are stored with the row and never published.

`actor.kind` is the kind of the audit actor, added to the audit entry for this purpose: `user`, `api_token`, `mcp_agent` or `system`.

### Listeners

A listener implements `DomainEventListener` (`listensTo()` returns an event name, `__invoke(RecordedEvent)`).

* A synchronous listener runs in the publisher's transaction, right after the row is inserted, and may listen to events of its own module only. A test fails otherwise. Whatever it does is committed or rolled back with the change.
* An asynchronous listener runs after commit, in its own `TransactionalWork`, and is the only way to react to another module's events. Releasing billed time on a trashed invoice stays on the synchronous `BilledWorkRelease` port, because restore can refuse when the time was billed elsewhere meanwhile and an asynchronous release could still be queued. `invoice.trashed` and `invoice.restored` are facts, not commands.

Pull Request 1 ships the engine and no production asynchronous listener; the tests build their own.

### Recording and the log

`EventRecorder::record()` is called by a use case inside `TransactionalWork`. Outside an active transaction it throws `\LogicException`, so an event cannot exist for a change that was not committed with it. The recorder writes a row to `event_log` (raw DBAL, Infrastructure only) in that transaction, together with the tenant, the actor, the correlation id (ADR 0007) and the facts as JSON. The table is append-only.

The row has no `position` yet. A database sequence cannot give an order that readers can follow: a transaction that started earlier can commit later, and a reader that has passed a higher position would never see the lower one (the same caveat as the audit id, ADR 0005). Positions are assigned after commit.

### The relay

After the outermost commit, `DoctrineTransactionalWork` tells the outbox, which dispatches a `RelayEvents` nudge on the `events` transport. A task on the schedule relays every 5 minutes the rows no nudge announced, for example after a crash.

The relay runs under one lock (Symfony Lock, `LOCK_DSN`). In one DBAL transaction it gives the committed rows without a position the next numbers, in order of id, continuing from the highest position, and enqueues one `DeliverEvent` message per asynchronous listener of each event. The Doctrine transport joins that transaction, so positions and deliveries are stored together or not at all. Positions are unique and gapless only if all workers share one lock store: the default file lock serves one host, so several hosts need a shared `LOCK_DSN`.

### Delivery

Delivery is at least once and ordering is not promised to listeners. `DeliverEventHandler` runs the listener in its own transaction and writes a consumption row keyed by `(listener, event_id)` in the same transaction; an event whose row exists is skipped, so a listener acts once per event even when the message arrives twice. A failure is retried after 5 s, 30 s, 3 min, 18 min and 1.8 h, then lands in the `failed` transport.

The listener key is its service id. Renaming or moving a listener changes the key, so it is treated as a new listener and receives again every event still in the log, which is 30 days of them. A listener that is renamed must tolerate that.

### The feed

`GET /api/v1/events` and the MCP tools `foresi_list_events` and `foresi_list_event_types` read the log in ascending order of `position`. Parameters are `types` (names or `module.*`), `cursor` and `limit`, the cursor being a position and not a UUIDv7 id. The read needs the `read` scope, and a type is shown only to a person who may read its subject. Unlike other lists, `next_cursor` is not null once an event has been seen, because a poller has to move past the last slice. The cursor then stays at the last position until a newer event exists.

A task on the schedule prunes events older than 30 days once a day. It never prunes the row with the highest position, so a position is never reused after the log has emptied.

### Consequences

* Good, because a module announces a fact once and listeners, the feed and later webhooks read one log.
* Good, because an event exists exactly when its change is committed, and a position is final when it is visible.
* Good, because the contract is checked in CI: names, versions, facts and the module prefix.
* Good, because the engine uses portable SQL and runs on SQLite and PostgreSQL.
* Good, because webhooks come next, on the same log: endpoints, signing and their own delivery rows, without a new source of events.
* Bad, because gapless positions need a lock store shared by all workers, which is an operational requirement.
* Bad, because a feed reader sees an event a moment after the commit, not in it.
* Bad, because renaming a listener's service id re-delivers 30 days of events.
* Bad, because a listener must be idempotent, and ordering across events is the reader's problem.
