# 35. One instance per customer

* Status: accepted
* Date: 2026-11-17
* Supersedes: [ADR 0003](0003-row-level-tenancy.md), [ADR 0017](0017-tenant-timezone.md), and the tenant parts of [ADR 0010](0010-several-firms-in-one-tenant.md)

## Context and problem statement

ADR 0003 built one installation for many tenants: a `tenant_id` on every tenant-owned table, a Doctrine filter that adds it to every query, a tenant stamped on every Messenger message, and an organisation chooser for users with several memberships. ADR 0017 gave each tenant its own time zone, and ADR 0010 put several firms inside one tenant.

With modules as packages (ADR 0034), each customer runs its own application with its own set of modules, so each customer also has its own database. A second tenant in the same database would have to share the first one's modules and versions, which is exactly what the packages are meant to avoid. Every running installation has a single tenant.

Measured on the code on 2026-11-17: 61 of 65 entities are `TenantOwned`, `TenantContext` is used in 69 files, raw DBAL queries name `tenant_id` in about 90 places, and about 288 test files set up a tenant. All of it guards a boundary that no installation uses, and every new table, query and test pays for it.

## Decision drivers

* One customer's data can never reach another's, by construction rather than by a filter.
* A new table, query or test needs no tenant boilerplate.
* The several firms of one organisation keep sharing users and contacts (ADR 0010).
* The live database moves without losing data and stops if it is not in the expected state.
* The schema baselines of ADR 0034 are generated without `tenant_id`.

## Considered options

* One instance per customer, tenants removed (chosen)
* Keep row-level tenancy, with exactly one tenant per instance
* Remove tenants later, after the packaging

## Decision outcome

Chosen option: "one instance per customer". An instance is one deployment with one database, and it serves one customer. Customers are isolated by having separate databases and deployments, not by a column.

### What is removed

* `TenantOwned`, the `tenant_id` column on every table and its indexes, the `tenant_id` DBAL type and the stamping of new entities.
* `TenantFilter` and `TenantFilterBypass`, so every query reads the whole database.
* `TenantMiddleware` and `TenantStamp` on the Messenger buses.
* The organisation chooser and `ActiveTenantStore`. A user is a member of the instance or not.
* The `tenant` table, once nothing refers to it.

### What stays

* **Firms.** An instance holds one or more firms (legal entities), which share its users and contacts. Every rule of ADR 0010 about firms stays and reads "instance" where it says "tenant": the issuer is chosen explicitly and frozen on the document, per-firm settings name their firm, a registration number is unique among the active firms of the instance, and the last active firm cannot be archived. Separating a firm becomes moving it to an instance of its own, and the rule that shared records hold no per-firm data keeps that possible.
* **Time zone.** The zone of ADR 0017 becomes application configuration, like the mailer or the locale: the parameter `app.timezone`, read from `APP_TIMEZONE`. Only canonical names of the tz database are accepted, and the application refuses to boot on any other value. Empty, it falls back to the country of the first active firm, and to UTC without one, so an instance works with nothing set. The instance's clock is Symfony's `ClockInterface` with that zone (`withTimeZone()`); times are still stored in UTC, and PHP's default zone is not changed. The day for scheduled work and reminders still comes from this one clock. The `tenant.timezone` column and the `app:tenant:timezone` command go away; a zone stored there is moved into the environment before the column is dropped.
* **Scheduled jobs, operator tasks and the heartbeat** move from Tenancy to the core. A tick runs each job once for the instance instead of once per tenant.

### Moving an existing database

Tenants are removed module by module, starting with the modules nothing depends on and ending with Tenancy itself.

* Before the first column is dropped, a guard migration checks that `tenant` holds exactly one row, and stops the migration otherwise. An installation with several tenants must first be split into instances; no migration merges them.
* Each module's migration drops `tenant_id` from its tables. A unique index or constraint that starts with `tenant_id` is rebuilt on its remaining columns, so uniqueness that held per tenant now holds per instance, which is the same thing with one tenant.
* A backup is taken before the first of these migrations runs on a live database.

### Consequences

* Good, because no query, cache or message can reach another customer's data: it is not in the database.
* Good, because tables, queries and tests lose a column, a filter and a set-up step, and the baselines of ADR 0034 are simpler.
* Good, because each customer moves to a new version on its own schedule.
* Bad, because every customer is a deployment and a database to run, back up and upgrade.
* Bad, because one person working for several customers signs in to each instance separately until single sign-on exists.
* Bad, because the removal touches nearly every module and test, so it runs as a sequence of changes that collide with other work in flight.
