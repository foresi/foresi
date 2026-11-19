# Contributing

Thank you for your interest in Foresi. This guide explains how to propose and submit changes.

## Before you start

- Search existing issues before opening a new one.
- For larger changes, open an issue first so the approach can be discussed.
- Contributions are accepted under the [Contributor Licence Agreement](CLA.md). You accept it by ticking the CLA checkbox ("I have read CLA.md and agree to its terms for this contribution") in the pull request template.
- Be kind. All participation is governed by the [Code of Conduct](CODE_OF_CONDUCT.md).

## Development setup

```bash
composer install
vendor/bin/phpunit
```

Requirements and the local setup are described in [docs/development.md](docs/development.md).

### Before you push: `bin/check`

`bin/check` runs the checks of a pull request locally, so that CI is not the first to find them:

1. It fixes the code with `composer cs:fix` and `rector process`. Review and commit what they change.
2. It runs every static check, each of them even when one fails, and lists the failed ones at the end: lint, phpstan, `modules:check`, `events:check` and deptrac (`bin/qa-static`, also `composer qa:static`; the Static checks step of CI runs the same script).
3. It runs the tests of the modules the branch touches: `tests/<Module>` for every changed file under `src/<Module>/` or `tests/<Module>/` since the merge base with `origin/main`. Changed files outside a module (configuration, templates, migrations, shared test helpers) are listed; CI runs the whole suite for them, and `--all-tests` does so locally.

```bash
git fetch origin main
bin/check
```

Options:

- `--infection` also runs those tests with coverage and Infection on the changed lines, with the thresholds of CI. The coverage comes from the touched modules' tests only, so Infection may report a mutant as escaped that a test in another module kills in CI. CI stays the judge.
- `--title TITLE --body-file FILE` also checks the pull request title, the commit subjects and the `## Release notes` section, as the Conventional Commits check does. Run it before `gh pr create` and before `gh pr edit --body`; `bin/pr-check --title TITLE --body-file FILE` does only this part, and `bin/pr-check NUMBER` checks an open pull request.
- `--all-tests`, `--no-tests`, `--no-fix` and `--base REF` change what runs and what the branch is compared with.

Measured on an 8-core Mac: the static part takes about 15 seconds with warm caches and about 90 seconds cold. The tests of the biggest module, Invoicing, take about 3 minutes with coverage, and Infection on the changed lines of a typical change about 15 seconds. A CI round takes about 12 minutes.

To run it on every push, add a pre-push hook to your clone (opt-in; it does not rewrite code, so a failure stops the push and you fix and commit):

```bash
printf '#!/bin/sh\nexec bin/check --no-fix\n' > "$(git rev-parse --git-common-dir)/hooks/pre-push" && chmod +x "$(git rev-parse --git-common-dir)/hooks/pre-push"
```

### PDF conformance check (veraPDF)

The tests of the invoice PDF validate it against PDF/A-3a with [veraPDF](https://verapdf.org) (Java 11 or newer). Install it with the official installer, or with `brew install verapdf`, and make sure `verapdf` is on your `PATH`; `VERAPDF_BIN` can point at the binary instead. Without it, these checks are skipped locally with a message. CI sets `REQUIRE_VERAPDF=1`, which turns a missing binary into a failure.

### PDF reading checks (Poppler)

The tests of the invoice PDF read its text and attachment with Poppler (`pdftotext`, `pdfdetach`, `pdfinfo`, `pdftoppm`; `brew install poppler` or `apt install poppler-utils`). Without it, these checks are skipped locally with a message. CI sets `REQUIRE_POPPLER=1`.

### ISDOC schema

The tests of the ISDOC file validate it against the published XSD. The package states no licence, so it is not in the repository: `composer isdoc:schema` downloads it into `var/isdoc-schema/` and refuses a file whose SHA-256 differs from the one pinned in `bin/fetch-isdoc-schema`. Without it, these checks are skipped locally with a message. CI sets `REQUIRE_ISDOC_SCHEMA=1`.

The bundled PDF fonts (Inter, SIL Open Font License) are committed under `resources/pdf-fonts/`. `bin/build-pdf-fonts` regenerates them from the Inter TTF release; you only need it to change the fonts.

### Mutation testing

`composer infection` runs the whole mutation test locally and enforces a minimum MSI of 80 and a covered MSI of 90. No mutant is skipped (see `infection.json5`), so it is slow.

CI splits the run so that it takes minutes, with the same thresholds. The suite runs once with coverage, in four `Tests with coverage (sqlite, shard N)` jobs side by side: `bin/test-shard config INDEX COUNT` gives each one a share of the test files, balanced by size, and each shard writes its coverage in PHP format. The `Tests with coverage (sqlite)` job merges the shards with phpcov into the XML report and one JUnit log, and fails if a shard is missing. Locally, `composer phpunit:coverage` gives the same report in one run. On a pull request, `Mutation testing (changed lines)` mutates only the lines the pull request adds or modifies. Every night on main and when started by hand, `Mutation testing` runs ten shards in parallel; a push to main does not run it, so the full run does not hold the runners that pull requests wait for. Every mutation job uses `--skip-initial-tests` on that coverage and `--only-covering-test-cases`, so a mutant runs only the test cases that cover its line, not every test in their files. Infection has no sharding option, so a shard is a set of source files chosen by `bin/infection-shard files INDEX COUNT`, balanced by file size. The `Quality gate (sqlite)` job, the required check, runs `bin/infection-shard merge`: it checks that every shard is present and enforces the minimums over the total of all shards, not per shard. If the shards drift apart in time, change the matrix and `SHARD_COUNT` in `.github/workflows/ci.yml` together.

### Module subsets

Nobody knows which modules an instance will have, so CI also installs and tests each module on its own, with the core and only the modules and libraries it requires: the `Module subsets` workflow, one job per `src/*/composer.json`. It runs after each merge to main, every night and when started by hand, not on a pull request: a pull request runs the full suite, and a coupling the workflow finds on main is fixed by a follow-up pull request. Each job runs `bin/module-subset composer <Module>`, which rewrites `composer.json` around that module, then `composer update 'foresi/*'`, which removes everything the module does not require and keeps the versions of `composer.lock`. `bin/module-subset prune` deletes the source and tests of the modules that are not installed. The job then boots the kernel, lints the container, routes and templates, runs the migrations on PostgreSQL and runs `tests/<Module>/`. A test under `tests/<Module>/Integration/<Other>/` runs only when `<Other>` is installed, so a test that needs another module goes there. A test that goes through every module reads the installed ones (`InstalledModules`) instead of a list, sample domain events are the core's (the address book's `party.*`), and helpers that tests of several modules share live in `tests/Support/`. To try one locally, run the same commands in a copy of the checkout, never in your own: they rewrite `composer.json` and delete code.

A module declares the third-party libraries its own code uses in its `composer.json`, with the root's constraint; the skeleton keeps the framework and the rest of the root's requirements.

## Rules for code

- PHP 8.4 and Symfony 7.4 LTS. Every PHP file starts with `declare(strict_types=1);` and starts with the block header comment `/* SPDX-License-Identifier: AGPL-3.0-only */`, laid out over three lines as `composer cs:fix` writes it (the fixer enforces it).
- Everything is written in English: identifiers, comments, documentation, commit messages and test names.
- Country-specific terms get international names in the core. Country-specific rules live in country modules.
- Money is never a `float`.
- Architecture rules are recorded in [docs/adr](docs/adr). A change that conflicts with an ADR needs a new ADR.
- A module declares the modules it uses in its own `src/<Module>/composer.json` (ADR 0029). When you add a dependency between modules, add it to `require` (or to `extra.foresi.optional`), run `composer update "foresi/*"` and `bin/console app:modules:deptrac`, and commit the manifest, the lock and `deptrac.modules.yaml`. Code a module needs only when an optional module is installed goes in `src/<Module>/Integration/<Other>/` and may use only `Foresi\<Other>\Contract\` (ADR 0033).
- Add or update tests with every behaviour change. Write the test first when you can.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `ci:`.
- Keep pull requests focused. One logical change per pull request.
- Describe a change users notice in the `## Release notes` section of the pull request (see the [template](.github/pull_request_template.md)). The Conventional Commits check refuses a feat, fix or perf pull request without it.
- Never commit secrets or personal data. Use obviously fictional data in tests.
- Pull requests are squash-merged. There is no merge queue, so a green pull request does not merge by itself: maintainers turn on auto-merge as soon as it is opened, and it then merges once the required checks pass:

  ```bash
  gh pr merge <number> --auto --squash
  ```

## Reporting security issues

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).

## Releases

Every merge to `main` that users notice is a release; there is no release branch and no release day. The Release workflow publishes a GitHub release and its tag for each merged pull request whose title is a `feat`, `fix` or `perf` commit, or breaking (`!`), with the pull request's Release notes as the notes. Other types publish nothing.

The version follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0.0, a breaking change raises the minor version (0.5.3 to 0.6.0) and anything else the patch (0.5.3 to 0.5.4). From 1.0.0, a breaking change raises the major version, a feature the minor one and a fix the patch.

Operators may skip versions: the migrations run in order whatever the gap, and the Upgrading notes of every release in between say what else to do. [CHANGELOG.md](CHANGELOG.md) holds the history up to 0.5.0.

