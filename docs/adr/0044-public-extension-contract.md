# 44. Public extension contract

* Status: accepted
* Date: 2026-11-25
* Amends: [ADR 0029](0029-module-registry.md) (manifest: any vendor, the module API range), [ADR 0033](0033-module-contracts-and-integrations.md) (the core's own contract), [ADR 0034](0034-module-packages.md) (namespace of third-party modules)
* Amended by: [ADR 0048](0048-unit-of-work-in-the-module-contract.md) (the unit of work of a module, module API 1.10)

## Context and problem statement

ADR 0034 says that a third-party module "uses its own vendor namespace", and ADR 0033 says that a consumer's port lets anyone contribute "without a change in the consumer". The code does not keep either promise yet. On main at 0.10.31:

* The interfaces that every module implements sit in the core's internals: `OperationHandler` and `OperationOutput` in `Shared\Infrastructure\Operation`, `ReadTool`, `WriteTool`, `ToolOutput`, `ToolRefusal` and `StaffOnlyTool` in `Shared\Infrastructure\Mcp`, the attributes `Operation` and `Field` and the exception `Refused` in `Shared\Application`, `DomainEventListener` in `Shared\Application\Event`, and `ScheduledJob` in `Instance\Application`. Between them they are used in more than 150 files.
* About 65 service tags exist, almost all named `app.*`.
* Nothing says what a module author may rely on. CONTRIBUTING says only that the version follows SemVer and that a breaking change raises the minor version before 1.0.
* The manifest has no range of core versions it works with, and nothing checks the installed modules at boot or before an upgrade.
* `InstalledModules` refuses a package not named `foresi/<id>` and a namespace other than `Foresi\<Module>\`. `ModuleBundle` writes `Foresi\` into the Doctrine and migration prefixes, and `ModuleIntegrations` and the MCP tool loader assume it too.
* No module from another vendor has ever been installed in a test.

## Decision drivers

* A module of any vendor installs with Composer, boots, migrates and runs with no edit in this repository.
* A module author can tell from the code itself whether something is a promise.
* A module breaks only when the contract it uses changes, not whenever the core releases.
* An incompatible set of modules is refused before it runs, at the latest before migrations touch the database.
* Rules that the PHP world already knows: Symfony's backward compatibility promise, deprecate in a minor and remove in a major.

## Considered options

* What is public: the `Contract\` namespaces plus a short list marked `@api` (chosen); everything that is not marked `@internal`, as in Symfony and Sylius
* Versioning: a version of the contract of its own (chosen); the release version
* Declaring the range: a Composer virtual package that the core provides (chosen); a field `extra.foresi.api` checked by our code (Kimai, Nextcloud); only a range on `foresi/core` (Sylius, Shopware)
* An incompatible module: stops the boot (chosen); is skipped with a warning (Nextcloud disables it)
* Module settings: the bundle's own configuration (chosen); a settings registry in the core

## Decision outcome

### What is public

These are the contract, and the rules below apply to them:

1. Every class, interface, trait, enum and attribute in a `Contract\` namespace of an installed module, the core's included (`Foresi\Shared\Contract\`, `Foresi\Instance\Contract\`).
2. The few classes outside `Contract\` that a module cannot do without, each marked `@api` in its docblock: `ModuleBundle` with its protected hooks, and the `ForesiKernel` trait. A test lists every `@api` class, so the list grows only on purpose.
3. Names that are not PHP: the manifest (`extra.foresi` and the range below), the service tags `foresi.*`, the names and payloads of contract events (ADR 0030), the Ui slots and the `@Ui` Twig components that screens of modules use.

Everything else is internal, even when PHP makes it `public`: `Domain\`, `Application\` and `Infrastructure\` of every module, service ids, container parameters, database tables, and another module's routes and translation keys. The REST API and the MCP server have their own rules (ADR 0027, ADR 0032), which this decision does not change.

Symfony and Sylius make all code public except what is marked `@internal` ([Symfony](https://symfony.com/doc/current/contributing/code/bc.html), [Sylius](https://docs.sylius.com/public/open-source/release-cycle/backwards-compatibility-promise.md)). Here that would turn the thousands of uses between module internals (ADR 0033) into promises at once. Shopware treats every extension point as "a long-term compatibility promise" and its DI service constructors as not public ([Shopware](https://developer.shopware.com/docs/resources/guidelines/code/public-apis.html)). Ours is that rule, and the `Contract\` namespaces that Deptrac already guards say where the extension points are.

### The core's contract

The interfaces above move to the core's own contract namespaces: `Foresi\Shared\Contract\Operation`, `Foresi\Shared\Contract\Mcp` and `Foresi\Shared\Contract\Event` for Shared, and `Foresi\Instance\Contract` for Instance. The values they pass and the exceptions they throw move with them. A helper that needs the core's infrastructure is split: the part that a module calls goes to the contract, and the rest stays internal. Like every contract, these depend only on the shared domain.

Every old name keeps working for at least one release with a new minor version, as a deprecated alias. The old file holds a `class_alias()` and a `trigger_deprecation()` call, which is how Symfony renames a class ([conventions](https://symfony.com/doc/current/contributing/code/conventions.html)). All code in this repository moves to the new names in the same change, so only code outside it uses the aliases.

### Tags

Every tag is named `foresi.<module>.<name>`, such as `foresi.shared.operation_handler` or `foresi.cash_flow.source`. Its name is the constant `TAG` of its interface, which carries `#[AutoconfigureTag(self::TAG)]`, so an implementation never writes a tag name.

Symfony has no way to deprecate a tag. A compiler pass in Shared therefore adds the new tag to every service that still carries an old public `app.*` name and reports a deprecation that names the service. Its map is kept for as long as the class aliases are. Tags of internal interfaces are renamed as well, but get no old name.

### The module API version

The contract has a version of its own: Foresi module API 1.0.0. A new interface, method of a value, tag or slot raises its minor version. Removing or changing any of them incompatibly raises its major version. The release version keeps the rules of CONTRIBUTING for everything else.

At 0.x, a release raises its minor version for any breaking change, whether to the REST API, to behaviour or to internals. A module that had to follow the release version would need a new release of its own after each of those, although nothing it uses changed. A version of the contract changes only when the contract does. Shopify versions its API apart from its releases for the same reason ([Shopify](https://shopify.dev/docs/apps/build/app-extensions)).

### Deprecation

* A part of the contract is deprecated in a minor version of the module API and removed no earlier than the next major version. A deprecation also ships in at least one release before the removal.
* The replacement exists when the deprecation is announced, and new code is never deprecated from its start. Both rules are Symfony's.
* A deprecation is a docblock that names the version that removes it, as Shopware's does: `@deprecated since module API 1.1, removed in 2.0, use X instead`. Code that runs it calls `trigger_deprecation()`.
* The release notes of the pull request say what was deprecated and what to use instead.

### The range in the manifest

`foresi/shared` declares `"provide": {"foresi/module-api": "1.0.0"}`. Every module, ours and anyone else's, declares the range it works with among its hard dependencies, where ADR 0029 keeps them: `"require": {"foresi/module-api": "^1.0"}`.

* Composer refuses to install or update into a set that does not fit, and `composer why-not foresi/module-api 2.0` names the modules that block an upgrade before it is tried. A virtual package is Composer's own tool for this ([schema: provide](https://getcomposer.org/doc/04-schema.md#provide)). Sylius and Shopware also leave the check to Composer, but against the release version of their core package.
* When the container is built, the kernel reads each module's range and the provided version, and stops with one message that names every incompatible module and its range. This catches a `vendor/` that Composer did not produce, such as a copied release. A manifest without the range is refused too.
* `bin/console app:modules:check` checks the installed modules against the running module API version, or with `--api=<version>` against a target version before an upgrade, and exits non-zero on a mismatch. `ops/deploy.sh` runs it after `composer install` and before the migrations. Nextcloud checks apps before their migrations too ([Updater.php](https://raw.githubusercontent.com/nextcloud/server/master/lib/private/Updater.php)).

An incompatible module stops the boot instead of being skipped, as in Kimai ([plugins](https://www.kimai.org/documentation/plugins.html)). Installed is enabled (ADR 0029), so a module that is installed but off is a state that does not exist.

### Any vendor and namespace

* Package: any `vendor/name`. A package of the `foresi` vendor is still named `foresi/<id>`.
* Namespace: any PSR-4 prefix of at least two segments, such as `Acme\Fleet\`. The bundle class sits in it.
* Module id: unique among the installed modules, as before. A third-party module should start its id with its vendor, such as `acme-fleet`, so that two vendors' modules never collide. Required modules are resolved from package names through the installed manifests.
* `ModuleBundle` takes the namespace from the manifest for the Doctrine prefixes (`<namespace>Domain`, `<namespace>Contract`) and for the migrations (`<namespace>Migrations`). It takes the extension alias and the asset namespace from the id (`acme_fleet`, `@acme-fleet`). Foresi's own modules keep the names they have today. `ModuleIntegrations` and the generated Deptrac configuration use each module's namespace.
* Names that a module claims start with its own prefix: `foresi` for ours, and the id for anyone else's. This applies to MCP tools (`acme_fleet_add_vehicle`), scopes, event names (`acme-fleet.vehicle.added`), route names, REST paths below `/api/v1/` and database tables. The core refuses two tools with the same name.

### Settings

A module's settings are its bundle's configuration: `AbstractBundle::configure()` defines them, and the application sets them in `config/packages/` or through environment variables. This is Symfony's standard and needs nothing new in the core. A registry of settings with a screen in the core is product work of its own.

### Proof

[`tests/Fixtures/modules/acme-fleet/`](../../tests/Fixtures/modules/acme-fleet) is a Composer package `acme/fleet` with the namespace `Acme\Fleet\`, outside `src/`, so that the development application never loads it by itself. It brings an entity with its migration, a screen with a navigation item, a write operation over REST and MCP, a read tool, a scope, an event of its own, a listener of a core event, a setting and its own tests. A CI job installs it with Composer from a path repository, runs `app:modules:check`, migrates an empty PostgreSQL database and runs its tests. A change that breaks it breaks the contract.

[`docs/modules.md`](../modules.md) is the guide for module authors: a tutorial that builds the example module, and a reference of every extension point with the module API version it first appeared in. The ADRs keep the reasons.

### Consequences

* Good, because a module of any vendor installs, boots and migrates with Composer alone, and CI proves this on every change.
* Good, because whether something is a promise can be seen in its namespace or in its `@api` mark.
* Good, because third-party modules follow the contract's version, not every release.
* Good, because an incompatible set is refused by Composer, and again at boot and before the migrations.
* Bad, because the move touches more than 150 files at once and collides with other work in flight on the same modules.
* Bad, because the aliases, the map of old tags and the check of the range are our own code to maintain until the next major version of the module API.
* Bad, because required dependencies between modules still reach into internals (ADR 0033), so a third-party module that requires a business module and uses its internals is not protected by this contract.
