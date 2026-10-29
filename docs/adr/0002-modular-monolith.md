# 2. Modular monolith

* Status: accepted
* Date: 2026-10-29

## Context and problem statement

The system covers several business areas (parties, identity, projects, deals, invoicing, bank, reports). It must stay simple to self-host while keeping the areas independent enough to evolve separately and to support country-specific rules.

## Decision drivers

* Easy self-hosting: one deployable application.
* Clear boundaries between business areas.
* Domain logic testable without a framework or a database.
* No country hard-coded in the core.

## Considered options

* Modular monolith with enforced layer rules
* Microservices
* Unstructured monolith

## Decision outcome

Chosen option: "modular monolith", because it keeps deployment simple and still allows strict boundaries.

### Rules

* PHP 8.4, Symfony 7.4 LTS and Doctrine ORM. The root namespace is `App\`.
* Each module lives in `src/<Module>/` and is split into `Domain`, `Application` and `Infrastructure` layers.
* `Domain` classes import nothing from `Symfony\`, `Doctrine\` or the `Infrastructure` layer of other modules. The only vendor namespace allowed in `Domain` is `Doctrine\ORM\Mapping`, because entities are mapped with PHP attributes.
* Raw DBAL access is not allowed in `Application` or `Domain`.
* Modules communicate through their `Application` layer, not through each other's internals. `Infrastructure` of one module is never used by another, with one exception: `TenantFilterBypass`, the sanctioned cross-tenant read.
* The shared kernel is `Shared\Domain` (money and other value types), which every module may use, and two small domain contracts that any module's `Domain` may use: `Tenancy\Domain` (`TenantOwned`, `TenantId`) and `Audit\Domain` (the `Audited` marker interface and the `AuditExcluded` attribute).
* Besides the shared kernel, a module's `Domain` may reference another module's `Domain` types only where the model needs it, and each allowed direction is declared in `deptrac.yaml`. Today `Identity` references `Party` (a membership links the party a user represents). Such references are entity associations to the other module's domain types, never calls into its services. The base modules `Tenancy` and `Audit` never depend on the modules built on them.
* Money is never a `float`. Amounts are stored as integer minor units plus an ISO 4217 currency code.
* Every tenant-owned table has a non-null `tenant_id` column and an index.
* Country-specific rules live in country modules (for example `src/Country/<Country>/`) and are registered through interfaces. The core uses international names and never hard-codes one country.
* The rules are enforced automatically in the quality gate (static analysis and architecture tests), not only by review.

### Consequences

* Good: one deployable unit, strong boundaries, framework-free domain code.
* Good: country modules can be added without touching the core.
* Bad: boundaries need discipline and tooling to stay intact.
