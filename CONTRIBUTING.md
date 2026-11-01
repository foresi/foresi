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

The bundled PDF fonts (Inter, SIL Open Font License) are committed under `resources/pdf-fonts/`. `bin/build-pdf-fonts` regenerates them from the Inter TTF release; you only need it to change the fonts.

## Rules for code

- PHP 8.4 and Symfony 7.4 LTS. Every PHP file starts with `declare(strict_types=1);` and starts with the block header comment `/* SPDX-License-Identifier: AGPL-3.0-only */`, laid out over three lines as `composer cs:fix` writes it (the fixer enforces it).
- Everything is written in English: identifiers, comments, documentation, commit messages and test names.
- Country-specific terms get international names in the core. Country-specific rules live in country modules.
- Money is never a `float`.
- Architecture rules are recorded in [docs/adr](docs/adr). A change that conflicts with an ADR needs a new ADR.
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
