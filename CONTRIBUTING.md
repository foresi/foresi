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

Requirements are listed in the [README](README.md).

### PDF conformance check (veraPDF)

The tests of the invoice PDF validate it against PDF/A-3a with [veraPDF](https://verapdf.org) (Java 11 or newer). Install it with the official installer, or with `brew install verapdf`, and make sure `verapdf` is on your `PATH`; `VERAPDF_BIN` can point at the binary instead. Without it, these checks are skipped locally with a message. CI sets `REQUIRE_VERAPDF=1`, which turns a missing binary into a failure.

### PDF reading checks (Poppler)

The tests of the invoice PDF read its text and attachment with Poppler (`pdftotext`, `pdfdetach`, `pdfinfo`, `pdftoppm`; `brew install poppler` or `apt install poppler-utils`). Without it, these checks are skipped locally with a message. CI sets `REQUIRE_POPPLER=1`.

### ISDOC schema

The tests of the ISDOC file validate it against the published XSD. The package states no licence, so it is not in the repository: `composer isdoc:schema` downloads it into `var/isdoc-schema/` and refuses a file whose SHA-256 differs from the one pinned in `bin/fetch-isdoc-schema`. Without it, these checks are skipped locally with a message. CI sets `REQUIRE_ISDOC_SCHEMA=1`.

The bundled PDF fonts (Inter, SIL Open Font License) are committed under `resources/pdf-fonts/`. `bin/build-pdf-fonts` regenerates them from the Inter TTF release; you only need it to change the fonts.

### Mutation testing

`composer infection` runs the whole mutation test locally and enforces a minimum MSI of 80 and a covered MSI of 90. No mutant is skipped (see `infection.json5`), so it is slow.

CI splits the run so that it takes minutes, with the same thresholds. The `Tests with coverage (sqlite)` job runs PHPUnit once with coverage (`composer phpunit:coverage`). Then `Mutation testing` runs six shards in parallel, each with `--skip-initial-tests` on that coverage. Infection has no sharding option, so a shard is a set of source files chosen by `bin/infection-shard files INDEX COUNT`, balanced by file size. The `Quality gate (sqlite)` job, the required check, runs `bin/infection-shard merge`: it checks that every shard is present and enforces the minimums over the total of all shards, not per shard. If the shards drift apart in time, change the matrix and `SHARD_COUNT` in `.github/workflows/ci.yml` together.

## Rules for code

- PHP 8.4 and Symfony 7.4 LTS. Every PHP file starts with `declare(strict_types=1);` and starts with the block header comment `/* SPDX-License-Identifier: AGPL-3.0-only */`, laid out over three lines as `composer cs:fix` writes it (the fixer enforces it).
- Everything is written in English: identifiers, comments, documentation, commit messages and test names.
- Country-specific terms get international names in the core. Country-specific rules live in country modules.
- Money is never a `float`.
- Architecture rules are recorded in [docs/adr](docs/adr). A change that conflicts with an ADR needs a new ADR.
- A module declares the modules it uses in its own `src/<Module>/composer.json` (ADR 0029). When you add a dependency between modules, add it to `require` (or to `extra.foresi.optional`), run `composer update "foresi/*"` and `bin/console app:modules:deptrac`, and commit the manifest, the lock and `deptrac.modules.yaml`.
- Add or update tests with every behaviour change. Write the test first when you can.

## Commits and pull requests

- Use [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `docs:`, `test:`, `refactor:`, `chore:`, `ci:`.
- Keep pull requests focused. One logical change per pull request.
- Update [CHANGELOG.md](CHANGELOG.md) under `Unreleased` for user-visible changes.
- Never commit secrets or personal data. Use obviously fictional data in tests.

## Reporting security issues

Do not open a public issue. Follow [SECURITY.md](SECURITY.md).

## Releases

A maintainer releases from `main`:

1. Move the `Unreleased` changes in [CHANGELOG.md](CHANGELOG.md) to a section for the new version, through a pull request.
2. Create an annotated tag whose name and message are both the version, such as `git tag -a v1.2.3 -m v1.2.3`.
3. Push the tag. The Release workflow publishes a GitHub release with that changelog section as its notes.
