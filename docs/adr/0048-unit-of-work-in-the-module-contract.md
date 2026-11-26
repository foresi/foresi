# 48. A unit of work in the module contract

* Status: accepted
* Date: 2026-11-26
* Amends: [ADR 0044](0044-public-extension-contract.md) (module API 1.10)

## Context and problem statement

A module records a contract event with `EventRecorder` in the transaction of the change. The event exists exactly when the change is committed. After the outermost commit, the internal `EventOutbox` hands the events to the relay, which delivers them to listeners and webhooks at once. After a rollback, the outbox drops them. Only a unit of work that knows the outbox tells it. Core had nine copies of one such unit, all internal or in Party.

A module of another vendor could only call Doctrine's `wrapInTransaction()`. That has three effects:

1. The outbox is never told, so the module's events wait for the relay's periodic sweep, up to five minutes.
2. After a failure the entity manager stays closed, and a caller that handles a refusal cannot query on.
3. Inside another unit (a listener the relay runs, or a module calling `TaskIntake`), it opens a savepoint. The savepoint flushes the outer unit's pending changes into it, and on a failure it closes the manager under the outer unit.

## Decision drivers

* A module stores its records and its events together, and the events leave as soon as the change is committed.
* The internals of the event log (the outbox, the relay) stay out of the contract.
* One behaviour, tested once, for core and third-party modules alike.
* Nothing is added before a module needs it.

## Considered options

* `EventOutbox` in the contract: a module calls `committed()` and `rolledBack()` itself. Rejected 2026-11-25, because every module would have to repeat the unit's rules and could get them wrong.
* A service with `begin()`, `commit()` and `rollBack()`. Every caller needs a try/finally. Platforms with this shape (Magento, Odoo) show the failure modes, such as an inner rollback that poisons the outer commit.
* An automatic transaction around every operation, like Messenger's `DoctrineTransactionMiddleware`. It does not cover screens, listeners, jobs or commands.
* A service that runs a closure (chosen), the shape of Doctrine's `wrapInTransaction`, Laravel's `DB::transaction` and Django's `atomic`.

## Decision outcome

`Foresi\Shared\Contract\UnitOfWork::run(callable $work): mixed`, since module API 1.10. `DoctrineUnitOfWork` implements it on the shared entity manager.

* **Nesting.** A unit inside another is part of it: it runs as it is, and the outermost unit flushes, commits or rolls back. Savepoints stay internal (row-by-row import), because Doctrine ORM cannot roll its in-memory state back to a savepoint.
* **Events.** `EventRecorder` stays as it is. After the outermost commit, the unit tells the outbox, and the relay is nudged once. A nudge that fails is logged and never fails the committed unit: the sweep delivers the event later.
* **Failure.** Any exception rolls back, drops the recorded events, replaces the entity manager (`ManagerRegistry::resetManager()`), and travels on unchanged. Entities loaded before the failure are detached. There is no retry on deadlock, because the closure may have side effects. A client retries with its idempotency key (ADR 0032).
* **No after-commit hook.** Work after the commit is an asynchronous listener of the module's own event. An `afterCommit()` callback, as in Django's `on_commit`, can come as a minor version once a module needs it.
* **Core.** The core modules keep their own ports (`TransactionalWork`, `CatalogWork`, the relay's `DeliveryWork` and the rest). Their implementations delegate to `DoctrineUnitOfWork`. Inbox, Activities and Documents add a correlation id, Inbox flushes into a joined transaction, and Sales turns a unique violation into a refusal.

### Consequences

* A module's events reach listeners and webhooks right after its commit, as Foresi's own do.
* The rule "a unit inside another is part of it" holds across modules, so a module can call another module's contract services, or open its own unit in a listener, without savepoints.
* `wrapInTransaction()` still works in a module. It is Doctrine's, not the contract's, so it is not deprecated, but the guide shows `UnitOfWork` and says why not to use it. If third-party modules grow in number, `EventRecorder` can warn when it records in a transaction no unit opened.
