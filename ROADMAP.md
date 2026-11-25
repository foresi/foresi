# Roadmap

Where Foresi is heading, without dates. The order can change; changes are announced in [Discussions › Announcements](https://github.com/foresi/foresi/discussions/categories/announcements). Ideas are welcome in [Discussions › Ideas](https://github.com/foresi/foresi/discussions/categories/ideas). What already shipped is in the [releases](https://github.com/foresi/foresi/releases) and, up to 0.5.0, the [changelog](CHANGELOG.md).

## Now

- **0.10.x, the current line** (latest: [0.10.31](https://github.com/foresi/foresi/releases/tag/v0.10.31)): every merged change is its own release. 0.5.0 (2026-11-18) was the first release with invoices; since then have come cash flow, the VAT and taxes and contributions modules, the margin and mail modules, one baseline migration per module (0.9.0) and every module as its own package.
- **One instance per customer**: each firm runs its own Foresi instance ([ADR 0035](docs/adr/0035-one-instance-per-customer.md)).

## Next

- **More of the API and MCP**: writes beyond contacts, so scripts and assistants can do what the screens do.
- **Documentation site** on [foresi.app](https://foresi.app), built from the docs in this repository.

## Later

- Countries beyond Czechia, as country modules.
- Sign-in across instances for people who work for several firms.
