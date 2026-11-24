# 33. Module contracts and integrations

* Status: accepted, amended by [ADR 0034](0034-module-packages.md) (integrations may hold controllers) and [ADR 0038](0038-core-screens-in-integrations.md) (templates, translations and Stimulus controllers)
* Date: 2026-11-17

## Context and problem statement

ADR 0029 gave every module a manifest and generated Deptrac rules from it, and left two things for later: a public `Contract/` namespace per module that other modules are limited to, and loading the code a module keeps for an optional dependency only when that dependency is installed.

Cash flow is the first module that needs both. Invoicing, Purchasing and third-party modules contribute expected payments to its forecast, yet none of them may need Cash flow to run, and Cash flow may know none of them. The contributor depends on the consumer, optionally: Cash flow publishes the interface, and Invoicing implements it in code that exists only for Cash flow.

Measured on the code at the start of this work: no module declares an optional dependency, Party and Invoicing publish their events in `Contract/Event`, and the modules use each other's internals in 65 module pairs, 2,593 class-level dependencies outside the shared kernel.

## Decision drivers

* A module runs unchanged when an optional dependency is absent: no `if` in business code, no class of the absent module on any path that runs.
* The code that couples two modules is in one place, named after the module it serves.
* What a module offers to others is a short, deliberate list, and an integration cannot reach past it.
* No change to how existing modules use the modules they require, so work in flight keeps building.

## Considered options

* Where the coupling code lives: in the contributing module under `Integration/<Other>/` (chosen); in a third bridge module per pair; in the consuming module, which would then know every source
* How it is switched on: the kernel registers the directory only when the optional dependency is installed (chosen); register it always and check at runtime; a Symfony bundle per integration
* How far "contract only" reaches: optional dependencies and integration code now, required dependencies later (chosen); every dependency now, with the 2,593 current uses in a Deptrac baseline

## Decision outcome

### Contracts

`App\<Module>\Contract\` is what a module publishes for other modules: interfaces they implement, values they pass and the events of ADR 0030 in `Contract\Event`. A contract depends only on the contracts of the modules its module requires and on the core it requires, never on its own module's internals, so it can be read and implemented without them. The hand-written `deptrac.yaml` keeps the stricter rule that a contract uses the shared domain only.

### Integrations

A module that uses another one only when it is installed lists it in `extra.foresi.optional` of its `composer.json` and keeps the code for it in `src/<Module>/Integration/<Other>/`, `<Other>` being the other module's directory (`CashFlow` for `cash-flow`). Each module's `config/services.php` leaves its `Integration/` directory out. When the container is built, the kernel asks `ModuleIntegrations` for the integration directories and registers each one whose module is installed, autowired and autoconfigured like the rest of `src/`. The container is rebuilt when `vendor/composer/installed.php` changes, so installing or removing a module turns its integrations on or off.

An integration directory for a module the manifest does not list as optional stops the container build with a message that names the directory; code that is never loaded must not hide. Integration code is services only: a class there is collected by a tag (`#[AutoconfigureTag]` on the contract's interface, then a tagged iterator in the consuming module), handles a message, or listens to an event. Doctrine entities and migrations stay in the module itself.

`bin/console app:modules` lists the integrations and whether each is loaded, and `--format=json` and the MCP tool `foresi_list_modules` return them as `integrations`.

### Deptrac

`app:modules:deptrac` now writes three kinds of layer per module:

* `<id>`: the module's own code, everything except `Contract\` and `Integration\`;
* `<id>_contract`: `App\<Module>\Contract\`;
* `<id>_integration_<other>`: `App\<Module>\Integration\<Other>\`, one per optional dependency.

The rules:

* `<id>` may use its own contract and the modules it requires, whole and with their contracts. Nothing of an optional dependency.
* `<id>_integration_<other>` may use its own module, the modules its module requires, and of `<other>` only `<other>_contract`, when `<other>` is installed. It may also use the contract of a module `<other>` requires when `<id>` lists that module as optional too: `<other>`'s contract may hand it such a value (Country's bank parsers read Banking's contract, which gives them Mail's `InboundMail`). Contracts only, one level deep, and only of modules installed whenever `<other>` is.
* `<id>_contract` may use the contracts of the required modules and the core modules it requires.

### Why required dependencies keep full access

Applying "contract only" to required dependencies today fails 2,593 dependencies in 65 module pairs. A baseline of that size records the present, not a decision, and every pull request in flight would have to grow it. Optional dependencies have no such history, so the rule is exact from the first one. Moving a required dependency to its contract is a change of its own, pair by pair, and is measured by the same generated configuration.

### Shared schedule

`Recurrence`, `RecurrenceUnit`, `RecurrenceEnd`, `RecurringSchedule` and `InvalidRecurrence` moved from `Invoicing\Domain` to `App\Shared\Domain\Schedule`. They never depended on Invoicing, and Cash flow repeats planned payments with the same rules without depending on Invoicing. Invoicing stores the unit and the end as the same strings, so the database does not change.

### Consequences

* Good, because a module with an optional dependency runs the same whether or not that module is installed, and the code that differs is in one directory.
* Good, because an integration can use only what the other module publishes, so that module can change its internals freely.
* Good, because the consumer owns its contract and knows no source; a third-party module contributes without a change in the consumer.
* Bad, because required dependencies still reach into each other's internals; the contract rule covers them only when a pair is moved.
* Bad, because a misnamed integration directory fails the container build, not just the quality gate.
