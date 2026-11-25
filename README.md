# Foresi

**Open-source business system for small firms.** Contacts, invoices, payments, received documents, projects and tasks in one application you run yourself, with a REST API and an MCP server so AI assistants can work with your data.

[![Licence: AGPL-3.0-only](https://img.shields.io/badge/licence-AGPL--3.0--only-blue)](LICENSE)
[![Latest release](https://img.shields.io/github/v/release/foresi/foresi)](https://github.com/foresi/foresi/releases)
[![CI](https://github.com/foresi/foresi/actions/workflows/ci.yml/badge.svg)](https://github.com/foresi/foresi/actions/workflows/ci.yml)

Website: [foresi.app](https://foresi.app) · [Releases](https://github.com/foresi/foresi/releases) · [Roadmap](ROADMAP.md) · [Discussions](https://github.com/foresi/foresi/discussions)

> **Status: early stage (0.x).** Foresi is in active development. Interfaces and data structures may change between releases. Czech rules (VAT, ISDOC, the business register) come first; other countries plug in as country modules.

## Who it is for

Owners of small firms who want one system for the whole run of the business, from the first contact to the paid invoice, and who want to own their data: Foresi is self-hosted, licensed under the AGPL and has no hidden cloud dependency.

## What it does

| Area | What you get | Docs |
| --- | --- | --- |
| Contacts | Customers and suppliers with addresses, contact persons and bank accounts; lookups in the Czech business register (ARES), the EU VAT check (VIES) and the unreliable VAT payer register | [import.md](docs/import.md) |
| Invoices | Invoices and advance invoices with VAT per rate and reverse charge, number series, PDF/A-3 with ISDOC and a QR payment code, a monthly pack for the accountant | [invoicing.md](docs/invoicing.md) |
| Sending and reminders | Invoices sent by e-mail through your own mail server, automatic reminders of overdue invoices | [sending.md](docs/sending.md), [reminders.md](docs/reminders.md) |
| Recurring invoices | Templates that issue invoices on a schedule | [recurring.md](docs/recurring.md) |
| Payments | Payments recorded by hand or paired from the bank's payment e-mails | [bank-pairing.md](docs/bank-pairing.md) |
| Mail | Inbound mail: the operator mailbox and the webhook, personal addresses with daily limits, who sent a message, and the raw files it is kept in | [mail.md](docs/mail.md) |
| Inbox | Your own e-mail address in Foresi: mail you forward there becomes an item matched to the sender's contact, for you alone | [inbox.md](docs/inbox.md) |
| Received documents | Supplier invoices, receipts and credit notes beside the original file, with VAT treatments and foreign currencies | [purchasing.md](docs/purchasing.md) |
| AI Reader | An optional reader that fills the header of a received document with an AI model, only for files the structured readers could not read | [ai-reader.md](docs/ai-reader.md) |
| Catalog | Products with their unit, price and a one-off or recurring way of billing | [catalog.md](docs/catalog.md) |
| Sales | Deals with stages, lines copied from the catalog, a value per currency, won or lost with a reason, and follow-up deals, on the client's page | [sales.md](docs/sales.md) |
| Cash flow | How much money the firm will have, by weeks and months, twelve months ahead | [cash-flow.md](docs/cash-flow.md) |
| Calendar | Secret iCal addresses that show what Foresi plans for you in your own calendar app | [calendar.md](docs/calendar.md) |
| Taxes and contributions | The owner profile with dated statuses, the base of the estimate of taxes and contributions | [taxes-and-contributions.md](docs/taxes-and-contributions.md) |
| VAT | The firm's VAT periods with their due dates, and where it pays its VAT | [vat.md](docs/vat.md) |
| Margin | Who bears the cost of a received document, the firm or a client, per line, with shares in CZK by month | [margin.md](docs/margin.md) |
| Documents | Contracts such as NDAs from versioned templates, frozen as PDFs, with signers and the signed copy beside the client | [documents.md](docs/documents.md) |
| Activities | Meetings, calls, notes and tasks logged or planned on a client, on its timeline with their files, and in your calendar | [activities.md](docs/activities.md) |
| Projects and tasks | Projects with your own workflow, tasks with subtasks, a board, comments and attachments | [projects.md](docs/projects.md) |
| People | Owners, team members and guests who see only what is shared with them | [people.md](docs/people.md) |
| Moving in | Import from CSV and XLSX, and a full move from iDoklad that can be undone for 30 days | [import.md](docs/import.md), [idoklad.md](docs/idoklad.md) |
| API and MCP | A REST API with personal tokens, an OpenAPI document, domain events and signed webhooks, and an MCP server for AI assistants | [api.md](docs/api.md), [mcp.md](docs/mcp.md) |

The interface is in Czech and English. Tax rules are subject to professional tax and legal review.

## Built to be trusted

- **Exact money**: amounts are integers in minor units with a currency; allocation never loses a cent.
- **Audit**: an append-only log of every change to audited records.
- **Quality gate**: PHPStan at maximum level, architecture rules checked by Deptrac, mutation testing with Infection, and Playwright end-to-end tests with accessibility checks (WCAG 2.2 AA).
- **Architecture**: a modular monolith on PHP 8.4, Symfony 7.4 and Doctrine ORM; every decision is recorded in [docs/adr](docs/adr).

## Get started

- Run it on your machine: [docs/development.md](docs/development.md)
- Run it on a server: [docs/operations.md](docs/operations.md)
- Write a module of your own: [docs/modules.md](docs/modules.md)

## Community

- Questions and ideas: [GitHub Discussions](https://github.com/foresi/foresi/discussions)
- Bugs: [issues](https://github.com/foresi/foresi/issues)
- Releases and news: the Announcements category of Discussions, or watch the repository for releases
- Contributing: read [CONTRIBUTING.md](CONTRIBUTING.md) and the [Code of Conduct](CODE_OF_CONDUCT.md); how decisions are made is in [GOVERNANCE.md](GOVERNANCE.md)
- Security problems: report them privately as described in [SECURITY.md](SECURITY.md)

## Licence

Licensed under the [GNU Affero General Public License v3.0 only](LICENSE) (AGPL-3.0-only).
