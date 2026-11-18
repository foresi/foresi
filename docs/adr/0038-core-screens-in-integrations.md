# 38. Core screens in Ui integrations

* Status: accepted
* Date: 2026-11-18
* Amends: [ADR 0033](0033-module-contracts-and-integrations.md) (what an integration may hold), [ADR 0034](0034-module-packages.md) (the core and Ui)

## Context and problem statement

ADR 0034 made the core Shared, Audit, Identity, Party, Attachment, Events, Webhooks and Instance, and Ui "the screens". It also let a core module require Ui until a "headless step" separated the screens from the core. Identity, Party and Webhooks require `foresi/ui` today, so the core cannot be installed without the screens, and an instance that serves only the REST API and MCP still carries every screen and Ui's components.

Measured on `main` on 2026-11-18:

* The screens of the three modules are about 31 PHP files (controllers, form readers, navigation, slot and column providers), 36 templates and 3 Stimulus controllers.
* They use 17 classes of Ui, of which 2 are published in `Ui\Contract`.
* `foresi/ui` ships no templates: its components, the layouts and `base.html.twig` live in the application's `templates/`.
* The application's home page `/` and the shell's view of the signed-in user are Identity code.
* Party already keeps its import screens in `Party/Integration/Import/`, loaded only when Import is installed.

## Decision drivers

* `foresi/core` installs, boots, migrates and serves the REST API and MCP without `foresi/ui`.
* No new package or split repository, and no business module changes what it requires.
* A screen stays next to the domain code it drives.
* Screens use only what Ui publishes, and Deptrac proves it.

## Considered options

* Where the core screens live: in the module, under `Integration/Ui/` (chosen); in new packages `foresi/<core>-ui`; in Ui itself
* Where the sign-in pages live: with the other screens, so they need Ui (chosen); in Identity's core with a layout of their own

## Decision outcome

### Ui is an optional dependency of the core

Identity, Party and Webhooks list `ui` in `extra.foresi.optional` and keep their screens in `src/<Module>/Integration/Ui/`: controllers, form readers, navigation, slot and column providers, templates, translations of the screens and Stimulus controllers. Their domain, application code, REST API, MCP tools, console commands and e-mail templates stay in the module. Every business module with screens already requires Ui, so its links to a core screen still resolve.

### What an integration may hold

This amends ADR 0033 and ADR 0034. Besides services and controllers, `Integration/<Other>/` may hold:

* `templates/`, registered under the module's Twig namespace (`@Party/...`), so a template keeps its name when it moves into the integration;
* `translations/`, added to the translator in the `messages` domain;
* `assets/controllers/`, Stimulus controllers mapped under `@foresi/<module>/<other>`, each keeping the name its file gives it.

The module's bundle registers all three only when `<Other>` is installed, the same switch as the integration's services and routes. Without Ui, none of these templates is registered, so `lint:twig` of a core-only application never meets a Ui component. A bundle can also ask whether one of its integrations is loaded, to prepend configuration that only the integration needs, such as the firewall of the sign-in pages.

### Ui publishes its extension points

Deptrac allows `<module>_integration_ui` to use only `ui_contract` (ADR 0033). Ui therefore publishes in `Ui\Contract` what a module's screens build on: navigation items and areas, slots, dashboard sections, the dialog request and the list column state. Ui's Twig components, layouts and `base.html.twig` move into the Ui package; templates use components by name, which Deptrac does not see.

### Sign-in moves with the screens

Login, logout, the second factor, recovery codes, invitation acceptance and the MCP OAuth consent page move to `Identity/Integration/Ui/`, with the session firewall (`main`) and the second-factor configuration, which Identity's bundle prepends when that integration is loaded. A core-only instance has no browser sign-in: people and API tokens are managed from the console (`app:user:create`, `app:api-token:issue`), and the API and MCP firewalls authenticate tokens without Ui. The home page `/` and the shell's view of the signed-in user move to Ui.

### The rule comes back

When the screens have moved, no core module requires Ui. `ModuleGraph::problems()` reports a core module that requires anything but another core module as `core-requires-module:<core>><module>`, Ui included, and the exception paragraph in ADR 0034 is removed. A CI job installs `foresi/core` without `foresi/ui` and checks that it boots, lists no screen route, migrates an empty database and answers the API and MCP with a token issued from the console.

### Consequences

* Good, because `foresi/core` alone is an API and MCP server, and the rule that the core needs nothing else holds again.
* Good, because `Ui\Contract` lists what any module's screens build on, for third-party modules too.
* Good, because a screen keeps its template names and its place next to its domain code.
* Bad, because a core-only install still downloads the screen code; it is never loaded.
* Bad, because a core-only instance has no browser sign-in, so an MCP client connecting with OAuth needs Ui; moving the sign-in pages into a Ui-free core later is a change of its own.
* Bad, because moving the screens and Ui's templates is mechanical work that collides with screen changes in flight.
