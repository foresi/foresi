# 29. Module registry

* Status: accepted
* Date: 2026-11-11

## Context and problem statement

Foresi is a modular monolith (ADR 0002): sixteen directories under `src/`, each a module. Nothing in the code says which modules exist, what each one needs, or which of them form the core. Services, Doctrine mappings, Messenger routing and migrations are registered centrally, and the allowed dependencies between modules are written by hand in `deptrac.yaml`. A module cannot be told apart from a directory, a dependency between two modules is visible only as `use` statements, and nothing stops a new edge from appearing.

Measured on the code: the modules use each other in 62 directions; Identity, Party and Import form a cycle; and Identity, which belongs to the core, uses Party and Ui.

## Decision drivers

* One place that says what a module is and what it needs, readable by tools and by people.
* No second switch beside the code: what is installed runs.
* A dependency that is not declared fails the quality gate.
* Modules can later become separate Composer packages without renaming anything.
* No behaviour change while the registry is introduced.

## Considered options

* Manifest: the module's own `composer.json` with an `extra.foresi` block (chosen); a PHP manifest class per module; a separate YAML file
* Enabled modules: installed in Composer is enabled (chosen); a configuration file listing them; a flag in the database
* Enforcement: a Deptrac configuration generated from the manifests and checked in CI (chosen); Deptrac rules kept by hand

## Decision outcome

### The manifest

Every module directory has a `composer.json` of type `foresi-module`, named `foresi/<id>`. The id is the directory name in kebab case (`ExchangeRate` is `exchange-rate`).

* `require` lists the modules it cannot run without, the hard dependencies.
* `extra.foresi.module` repeats the id; `extra.foresi.kind` is `core`, `module` or `ui`; `extra.foresi.namespace` is the module's PHP namespace (`App\Timesheet\`).
* `extra.foresi.optional` lists the ids of modules it uses only when they are installed. An id is never both required and optional.

The core is Shared, Identity, Audit, Attachment and Tenancy. Ui is the screens. Every other module is a business module. The manifests declare no autoloading: the root `App\` → `src/` mapping stays the only one.

### Installed is enabled

The root `composer.json` adds a path repository over `src/*` and requires every module, so `vendor/foresi/<id>` links to `src/<Module>`. The path packages carry the fixed version `dev-main`, set in the repository options, so the lock does not depend on the branch being built. An application leaves a module out by not requiring it. There is no runtime toggle and no state in the database.

### The registry

`Shared\Domain\Module` holds the graph: `ModuleId`, `ModuleKind`, `ModuleManifest`, `ModuleGraph` and `GraphProblem`, with no vendor code. `ModuleGraph` refuses a duplicate id and a hard dependency that is not installed; an absent optional dependency is fine. It orders modules dependencies first and reports two kinds of problem with stable codes: a group of modules that depend on each other in a cycle, one problem per group, named by the shortest cycle from its smallest id along the edges (`cycle:identity>party>identity`) and a core module requiring a non-core one (`core-requires-module:identity>party`).

`Shared\Infrastructure\Module\InstalledModules` reads the packages of type `foresi-module` from `Composer\InstalledVersions` and each one's `composer.json`. `bin/console app:modules` lists them (`--format=json` for scripts), and the MCP tool `foresi_list_modules` returns the same data.

### Known problems

`extra.foresi.accepted-problems` in the root `composer.json` lists the problem codes the application lives with for now. `app:modules` fails on any other problem, and a test fails when the list and the graph differ either way, so a fixed problem must leave the list too. Today it holds the group of Identity, Party and Import (named by its shortest cycle, Identity and Party), and Identity requiring Party and Ui. Editing the list changes the lock's content hash; refresh it with `composer update "foresi/*"`.

### Generated Deptrac

`bin/console app:modules:deptrac` writes `deptrac.modules.yaml`: one layer per module (the id with `_` for `-`, as Deptrac normalizes keys), and each module may use the modules it requires and its installed optional ones. The file is committed. `composer qa:static` runs `app:modules:deptrac --check`, which fails when the file differs from the manifests, and then analyses with both `deptrac.yaml` and `deptrac.modules.yaml`. The hand-written file keeps the layer rules inside modules until the generated one covers them.

### Later

Public contracts in a `Contract/` namespace per module (the event catalogue of ADR 0030 reads `Contract/Event` of the installed modules), which other modules will be limited to, come with the next steps, as do conditional loading of integration code for optional dependencies and ordering migrations by the graph.

### Consequences

* Good, because the manifest is the single statement of a module's dependencies, and an undeclared one fails CI.
* Good, because modules can move into their own packages without a new manifest format or new namespaces.
* Good, because the problems of today's graph are named and counted, and the list can only shrink deliberately.
* Bad, because a new module dependency now means editing the manifest, refreshing the lock and regenerating the Deptrac file.
* Bad, because two Deptrac configurations run side by side until the generated one replaces the hand-written one.
