# 34. Module packages

* Status: accepted, amended by [ADR 0038](0038-core-screens-in-integrations.md) (core screens in Ui integrations)
* Date: 2026-11-17
* Amends: [ADR 0029](0029-module-registry.md) (core set, namespace, autoloading), [ADR 0033](0033-module-contracts-and-integrations.md) (controllers in integrations)

## Context and problem statement

ADR 0029 gave every module a manifest and made "installed in Composer" the only switch, and ADR 0033 let a module use another one only through its contract when it is optional. Both kept one application in one repository: every module lives under `src/`, the root `App\` → `src/` autoloading is the only one, and services, Doctrine mappings, Messenger routing, routes, templates, translations and Stimulus controllers are configured centrally.

An application that installs only some modules is still not possible. Measured on the code on 2026-11-17, with 19 manifests:

* `config/services.yaml` hard-wires 7 cross-module aliases, so removing Banking or Timesheet leaves interfaces without an implementation and the container fails.
* Doctrine mappings (15), Messenger routing (18 lines), security rules, Twig templates and translations of every module are listed centrally, and stay configured when a module is absent.
* Templates link to routes of other modules (a firm's tabs to Invoicing and Banking, the shell to Work's running timer), and some core interfaces have their only implementation in a business module.
* All 85 migrations are global and 41 of them touch more than one module, so a new database always gets every module's tables.

The modules should become Composer packages that an application installs as it needs, each of them shipped with its own configuration, screens and schema, and published so that anyone can run the same set.

## Decision drivers

* An application with a subset of modules boots, migrates an empty database and runs, with no table, route or template of an absent module.
* A module brings everything it needs; nothing about it is listed in the application.
* "Installed is enabled" (ADR 0029) stays the only switch, for bundles too.
* Free, public distribution of AGPL code that self-hosters install with plain Composer.
* One repository to develop in, one version for all modules.
* The live database moves to the new layout without running DDL.

## Considered options

* Package shape: a Symfony bundle per module (chosen); plain PSR-4 packages with configuration left in the application
* Core: Party joins the core (chosen); Party stays a business module and Identity keeps its own person profile
* Namespace: `Foresi\<Module>\` before the first release (chosen); keep `App\<Module>\`
* Migrations: one baseline per module, replacing the history (chosen); split the existing migrations along module lines; keep the old ones global and add new ones per module
* Distribution: monorepo split into read-only repositories, published on Packagist.org (chosen); Private Packagist from the monorepo; a self-hosted Satis; path repositories over a clone of the monorepo

## Decision outcome

### A module is a bundle

Each module stays in `src/<Module>/` of this repository, and that directory is the package root:

```
src/Work/
  composer.json        type foresi-module, PSR-4 autoload, extra.foresi (ADR 0029), extra.foresi.bundle
  WorkBundle.php       extends AbstractBundle
  config/services.php  the module's services
  config/routes.php    the module's routes
  Contract/            what other modules may use (ADR 0033)
  Domain/ Application/ Infrastructure/
  Integration/<Other>/ code for an optional dependency (ADR 0033)
  migrations/          the module's migrations
  templates/           Twig, namespace @Work
  translations/        the module's keys, cs and en
  assets/controllers/  the module's Stimulus controllers
```

Tests stay in `tests/<Module>/` of the monorepo, so the packages ship none.

The bundle owns what is configured centrally today:

* **Services** are loaded from `config/services.php` in `loadExtension()`. `Domain/` entities and `Integration/` stay excluded; integrations are still registered by the kernel when their module is installed (ADR 0033).
* **Doctrine mappings** are prepended by the bundle. Custom types and filters move with the module that owns them.
* **Messenger routing** moves to `#[AsMessage(transport: ...)]` on each message class. Transports and middleware stay in the core, except a module's own middleware, which its bundle prepends.
* **Routes** are imported by a core route loader from `config/routes.php` of every installed module.
* **Security**: public paths and firewalls a module needs are prepended by its bundle, so they precede the application's generic rules.
* **Templates** live under the module's Twig namespace (`@Work/...`).
* **Translations** are loaded from the bundle. The single `messages` domain is kept on purpose: the keys are already prefixed by module (`work.*`, `invoice.*`), and a domain per bundle would change every call site for no gain.
* **Stimulus controllers and assets** are registered by the bundle in the asset mapper and in `stimulus.controller_paths`. Generic controllers stay in Ui.
* Navigation, MCP tools, operations and scheduled jobs are already collected by tags and work across bundles unchanged.

### Installed is enabled, for bundles too

Flex registers a bundle only through a recipe, which would bring back a second switch. The kernel therefore registers the bundle named in `extra.foresi.bundle` of every installed `foresi-module` itself, from `InstalledModules`, in `registerBundles()`. `config/bundles.php` lists third-party bundles only. `InstalledModules` reads each package's path from `Composer\InstalledVersions` and its namespace from the manifest, instead of assuming `src/` and `App\`.

### Namespace

Modules move from `App\<Module>\` to `Foresi\<Module>\` before the first release, and each manifest declares its own PSR-4 autoloading. `App\` belongs to the application that installs the modules; a third-party module uses its own vendor namespace. Class names stored in data (`audit_entry.entity_class` and `audit_entry.root_class`) are rewritten by a one-off migration in the same release, and the Messenger queues are drained before it is deployed. After the first release, renaming a namespace would be a breaking change.

### Dependency rules

1. A module requires only the core and the modules it exists to extend. A bridge module that connects exactly one pair (an importer into Invoicing, billing of Work into Invoicing) may require both sides.
2. Any other use of a module is optional: listed in `extra.foresi.optional`, with the code in `Integration/<Other>/`, using only `<Other>\Contract` (ADR 0033).
3. The consumer owns the port. A module that needs something from others publishes an interface in its `Contract/`. Providers implement it and are collected by tag. With no provider installed the consumer gets an empty list or a neutral default, and the container never depends on a class of an absent module. This replaces the hard-wired cross-module aliases and every core interface whose only implementation is in a business module.
4. Screens are extended the same way. A template never links to a route of another module. It offers a slot (a firm's tabs, items of the user menu, widgets of the shell, actions of a project, the dashboard), and providers fill it by tag, as the navigation registry already works. A link from one module's PHP code to another's route goes through a port that returns the link, or nothing.

### The core

The core is Shared, Audit, Identity, Party, Attachment, Events and Webhooks. Ui is the screens. What remains of Tenancy after [ADR 0035](0035-one-instance-per-customer.md) joins the core.

Party is in the core because every business module and Identity need it: a member is a person contact. The cycle between Identity and Party is broken by a port in Shared, "the role of the current actor in a firm", which Identity implements, so Party no longer uses Identity. Party's use of Import moves to `Party/Integration/Import/`.

Each core module stays a package of its own. The metapackage `foresi/core` (in `packages/core/`, outside `src/` so that it is not read as a module) requires them all, and an application requires `foresi/core` plus the business modules it wants.

Until the headless step separates the screens from the core, a core module may require Ui: Identity, Party and Webhooks have screens, and Ui is part of every application today. The module graph does not report a core module requiring Ui as a problem. The headless step removes this exception, and the graph reports such a requirement again.

### Integrations may hold controllers

This amends ADR 0033, which allowed only services in `Integration/<Other>/`. An integration may also hold controllers: a module's screens for another module, such as Party's import pages, belong with the rest of the coupling. The module route loader imports the routes of `Integration/<Other>/` only when `<Other>` is installed. Doctrine entities and migrations still stay out of integrations.

### Migrations

* Each module's current schema becomes one baseline migration in its package, generated from the live schema split by table ownership. Every table belongs to exactly one module, including tables with no entity (`messenger_messages` to the core, the event log to Events, the API idempotency keys to Identity), and a check fails on a table with no owner. The 85 global migrations and their tests are deleted.
* The baselines are generated after tenants are removed (ADR 0035), so they contain no `tenant_id`.
* A core comparator replaces Doctrine's `Comparator` service. It orders migrations by the timestamp in the class name, then by the module's rank in the dependency graph. All baselines share one timestamp, so a new database creates them in graph order and every cross-module foreign key finds its table. A later migration that references another module's table is newer than that table, and is in a module that requires it. This needs an acyclic graph, which the Identity and Party port above provides.
* The baselines use the schema API, as today, so they run on PostgreSQL and on SQLite.
* An existing database moves with `app:migrations:rebase`. It checks that every old version is executed and that the schema is in sync with the mapping, then replaces the old version rows with the baselines' in one transaction, without DDL. On any other state it refuses to run. A backup is taken before it runs.
* CI proves the move: a database built from the old migrations and rebased has the same schema dump as one built from the baselines alone, and baselines of a subset of modules build on an empty database.

### Distribution

* This repository stays the only place where modules are developed. On every push to `main` and on every tag, an action splits each `src/<Module>/` into its own read-only repository `foresi/<module>`. Pull requests opened there are closed with a pointer to this repository.
* The split repositories are published on Packagist.org, so `composer require foresi/work` works with no extra repository.
* Versions are in lockstep: one tag here is pushed to every split repository. Inside the monorepo, modules require each other at `self.version`, and the root `replace`s them. Under 0.x, `^0.5` stays within `0.5`, so applications move to a new minor version only when they choose to.
* A public skeleton, `foresi/skeleton`, is the starting point of an application: a kernel that uses the core's kernel trait, `composer.json` with `foresi/core`, `foresi/ui` and a default set of modules, and the deploy script. A module kept private by an application lives in that application's repository as a path repository.
* The monorepo root remains the development application: it installs every module through path repositories and runs all tests.
* CI builds subsets of modules (all, Work only, core only) and checks that each boots, lists no route of an absent module, migrates an empty database without tables of absent modules, and passes the tests of its installed modules.

### Consequences

* Good, because an application installs only the modules it uses, and its database has only their tables.
* Good, because a module carries its whole configuration, so adding or removing one is a Composer change and nothing else.
* Good, because self-hosters install the same packages from Packagist.org, at no cost.
* Good, because ports and slots let anyone replace a module (bring your own invoicing) by implementing published interfaces.
* Bad, because the namespace rename, the bundles and the move of templates, translations and assets are large mechanical changes that collide with work in flight and must run when no other change is open on the same modules.
* Bad, because the existing migration history is replaced by baselines; how the schema got where it is lives only in git.
* Bad, because the split needs about twenty read-only repositories and a token with write access to them.
* Bad, because Doctrine does not order migrations across namespaces by itself; the comparator is our code to maintain.
