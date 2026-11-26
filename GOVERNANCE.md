# Governance

Foresi is a young project with a single lead maintainer. This page says who decides what and what you can expect when you take part. It will change as the project grows.

## Roles

- **Lead maintainer**: sets the direction and the roadmap, reviews and merges pull requests, and cuts releases. Today this is the project's founder.
- **Contributors**: anyone who opens an issue, answers in Discussions, improves the docs or sends a pull request. Code contributions are accepted under the [CLA](CLA.md).
- **Steward**: the project, its name and its GitHub organisation are held by Valmia Solutions s.r.o., which signs the CLA and keeps the code under the AGPL.

New maintainers are invited from regular contributors once they have a record of good reviews and pull requests.

## Reviews

Every change reaches `main` through a pull request whose required checks pass: the tests with mutation testing, the static analysis, the end-to-end tests and the Conventional Commits check. No one can push to `main` directly or bypass these rules, the lead maintainer included. With one maintainer there is no second person to approve a pull request, and GitHub does not let authors approve their own, so pull requests need no approval yet. The automated checks stand in for it, together with the workflow audit (zizmor), code scanning, the dependency review and the REUSE check. Required review by a second person, through [CODEOWNERS](.github/CODEOWNERS), starts when there is a second maintainer. Until then the OpenSSF Scorecard checks Code-Review and Branch-Protection stay low, and we say so rather than work around them.

## How decisions are made

- **Ideas and features** start in [Discussions › Ideas](https://github.com/foresi/foresi/discussions/categories/ideas). An idea becomes an issue once its scope is clear.
- **Architecture** is decided in an Architecture Decision Record in [docs/adr](docs/adr), proposed in a pull request and open for comment there.
- **The roadmap** in [ROADMAP.md](ROADMAP.md) is kept by the lead maintainer, who explains changes to it in the Announcements category.
- When there is no agreement, the lead maintainer decides and writes down why.

## What to expect

These are aims, not guarantees, from a small team:

- A first answer to a new issue or pull request within a week.
- Security reports handled first, as described in [SECURITY.md](SECURITY.md).
- Releases follow [Semantic Versioning](https://semver.org); every merged change that users notice is its own release, and its notes list the upgrade steps ([CONTRIBUTING.md](CONTRIBUTING.md#releases)).

## Conduct

Everyone who takes part follows the [Code of Conduct](CODE_OF_CONDUCT.md).
