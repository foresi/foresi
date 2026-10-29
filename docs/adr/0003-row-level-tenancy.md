# 3. Row-level tenancy

* Status: accepted
* Date: 2026-10-29

## Context and problem statement

One installation serves several tenants (companies), and one user may belong to more than one of them. Data of different tenants must never mix, and a forgotten condition in a query must not leak rows.

## Decision drivers

* Isolation by default, not by developer discipline.
* One database and one schema to operate and back up.
* A user working in one tenant at a time, chosen explicitly.

## Considered options

* Row-level tenancy: a `tenant_id` column on every tenant-owned table, enforced by a Doctrine SQL filter
* One schema per tenant
* One database per tenant

## Decision outcome

Chosen option: "row-level tenancy", because it keeps operations simple and lets one enforcement point cover all queries.

### Rules

* Every tenant-owned entity implements `TenantOwned` and has a non-null, indexed `tenant_id` column.
* A Doctrine filter adds the active tenant to every ORM and DQL query. It is enabled by default and fails closed: reading without an active tenant throws instead of returning everything.
* `TenantContext` holds the active tenant. The tenant is stamped on new entities at persist time, and writing an entity of another tenant is rejected.
* The active tenant is never guessed: a user with a single membership has it selected automatically, and a user with several memberships has no active tenant (so no tenant-owned data is reachable) until one is chosen. Switching is implemented as the `SwitchTenant` application service, which refuses tenants the user is not a member of. A tenant chooser in the user interface does not exist yet. Actions never run in more than one tenant at once.
* Cross-tenant lookups (for example listing a user's memberships) go through `TenantFilterBypass`, which returns scalar values only and never entities.
* Raw DBAL access is forbidden in `Application` and `Domain` (enforced by Deptrac), because it would bypass the filter.

### Consequences

* Good: a missing condition cannot leak data across tenants.
* Good: a single schema and a single backup.
* Bad: tenants share tables, so noisy-neighbour effects and a database-wide incident affect all tenants.
* Bad: every new tenant-owned table must follow the rules, which tests and the quality gate check.
