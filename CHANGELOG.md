# Changelog

All notable changes to Foresi are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and Foresi adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-10-30

### Added

- Design system: Twig components built on Symfony UX and Tailwind design tokens (buttons, inputs, badges, tabs, cards, data table with selection and bulk actions, pagination, KPI strip, facts grid, timeline, empty states and a command palette), with a development component catalogue.
- App shell: sidebar navigation, user menu, active tenant choice and a styled login screen in Czech and English.
- Address book: list with search, views, sorting and paging, detail with overview, contact persons, addresses, bank accounts and history, create and edit forms, archive and restore, bulk archive and CSV export.
- Parties: roles, billing and delivery addresses, persons linked as contact persons, bank accounts and document defaults.
- Registry lookups: Czech business register (ARES) autofill, EU VAT number check (VIES) and the Czech unreliable VAT payer register, with rate limiting.
- End-to-end tests with Playwright covering accessibility (axe), keyboard use, layout on desktop and mobile, and visual regression.
- Architecture decision record for the server-rendered user interface.

### Fixed

- Logout is a POST request protected by a CSRF token.
- The session is migrated when the active tenant changes.

## [0.1.0] - 2026-10-29

### Added

- Symfony 7.4 application skeleton with Doctrine ORM, Security, Twig, Validator and UID.
- Community files: licence, contributing guide, code of conduct, security policy and contributor licence agreement.
- Architecture decision records for the decision log, the modular monolith, row-level tenancy, money as integer minor units and the append-only audit log.
- Quality gate: PHPStan at maximum level, Deptrac layer rules, Rector, PHP CS Fixer and Infection mutation testing.
- Money: an exact `Money` value object with allocation that never loses or gains a minor unit.
- Tenancy: row-level tenant isolation with a fail-closed Doctrine filter, a tenant context and a scalar-only bypass for cross-tenant lookups.
- Parties: legal entities and parties with registration numbers, VAT IDs and IBANs; Czech registration number validation as the first country module.
- Identity: users, tenant memberships with roles, form login with CSRF protection and throttling, a tenant switch application service and the `app:user:create` command.
- Audit: an append-only audit log for audited entities.
- Tested on SQLite and PostgreSQL 17.
