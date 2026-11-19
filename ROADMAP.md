# Roadmap

Where Foresi is heading, without dates. The order can change; changes are announced in [Discussions › Announcements](https://github.com/foresi/foresi/discussions/categories/announcements). Ideas are welcome in [Discussions › Ideas](https://github.com/foresi/foresi/discussions/categories/ideas). What already shipped is in the [releases](https://github.com/foresi/foresi/releases) and, up to 0.5.0, the [changelog](CHANGELOG.md).

## Now

- **0.5.0, the first release with invoices**: invoicing, sending, reminders, recurring invoices, payments and bank pairing, received documents, projects and tasks, cash flow, and the move from iDoklad, released on 2026-11-18. From then on every merged change is its own release.
- **One instance per customer**: each firm runs its own Foresi instance ([ADR 0035](docs/adr/0035-one-instance-per-customer.md)).

## Next

- **Modules as Composer packages**: the core and each module published as packages, so an installation picks the modules it needs and adds its own ([ADR 0034](docs/adr/0034-module-packages.md)).
- **More of the API and MCP**: writes beyond contacts, so scripts and assistants can do what the screens do.
- **Documentation site** on [foresi.app](https://foresi.app), built from the docs in this repository.

## Later

- Countries beyond Czechia, as country modules.
- Sign-in across instances for people who work for several firms.
