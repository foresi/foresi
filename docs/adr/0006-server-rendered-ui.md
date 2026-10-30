# 6. Server-rendered UI with Symfony UX and Tailwind design tokens

* Status: accepted
* Date: 2026-10-30

## Context and problem statement

Foresi needs a user interface that stays consistent across many modules, is easy for contributors to extend, works well when self-hosted and meets WCAG 2.2 AA. How should the interface be built?

## Decision drivers

* One component vocabulary and one visual logic across all modules.
* No separate frontend build chain or API layer just to render screens.
* Contributors write PHP and Twig, not a second application in another stack.
* Accessibility and layout are checked automatically in the browser; visual regressions are checked by screenshot comparison.

## Considered options

* Server-rendered Twig with Symfony UX (Turbo, Stimulus, Twig and Live Components) and Tailwind
* A single-page application (React or Vue) on top of a JSON API
* An admin generator (EasyAdmin or Sonata)

## Decision outcome

Chosen option: "server-rendered Twig with Symfony UX and Tailwind", because it keeps the whole application in one stack, renders fast without client-side state and lets every module reuse the same components.

### Rules

* Screens are built only from components in `templates/components/Ui` with classes in `src/Ui/Component`. A new pattern becomes a component first and appears in the development catalogue. A screen may add layout classes (grid, spacing, widths) around components, but not its own copy of a component's look.
* Colours, radii and sizes come from the design tokens in `assets/styles/app.css`, in templates and in component classes alike. Neither uses a hexadecimal colour value. The only colour literals outside the tokens are the shadows in `src/Ui/Component/Style.php`, which use the ink colour with some transparency.
* Component inputs are validated when the component mounts: unknown variants, icons outside the locked Lucide set and unsafe links throw.
* Assets are served through AssetMapper with Tailwind's standalone binary; no Node.js toolchain is needed to run the application.
* Screens are checked by Playwright end-to-end tests with axe accessibility checks, keyboard checks, layout checks and screenshot comparison on desktop and mobile widths. This is the goal for every screen; today the sign-in screen, the address book list, detail and form, the empty address book and the component catalogue have specs (the catalogue for accessibility only). The dashboard, the organisation choice and the contact edit form have none yet. Screenshot comparison runs locally against per-platform baselines (only macOS baselines exist) and is not part of CI yet: CI runs everything except the `@visual` specs until Linux baselines are generated.

### Consequences

* Good: one consistent interface and one language for contributors.
* Good: pages work without heavy client-side JavaScript and are cheap to self-host.
* Bad: highly interactive screens need Live Components or Stimulus controllers instead of a client-side framework.
* Bad: screenshot baselines depend on the platform's font rendering, so they are kept per platform.
