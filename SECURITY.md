# Security policy

## Supported versions

Foresi is under active development and has not reached 1.0.0; the releases are 0.x versions, and every merged change that users notice is published as a [GitHub release](https://github.com/foresi/foresi/releases). Security fixes are applied to the default branch and reach you in the next release, so run the latest one.

## Inbound surface

Most of Foresi is behind sign-in. These parts take input from outside it:

- **Public document links** (`/d/<token>`): a 256-bit token, stored only as a hash, rate limited per IP. See [docs/sending.md](docs/sending.md#the-public-link).
- **Bank e-mail.** Mail to a bank account's inbound address, taken from the operator's mailbox over IMAP or posted to the optional webhook `POST /inbound/mail`. The address carries a 128-bit token, stored only as a hash and a sealed copy. The webhook needs a bearer secret (`INBOUND_WEBHOOK_SECRET`; empty turns it off), checks the secret first and rate limits failed attempts per IP, with a separate, higher limit for requests that carry the secret, takes at most 25 MiB, keeps at most `MAIL_INBOUND_DAILY_LIMIT` messages per address and day, and answers alike for known, unknown, revoked and over-limit addresses. A bank message pays an invoice only when the topmost `Authentication-Results` header comes from a receiving server the operator trusts for the channel the message took (`MAIL_INBOUND_TRUSTED_AUTHSERV` for IMAP, `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` for the webhook, never one for the other) and shows a single aligned `dmarc=pass`, or the strict ARC rescue applies. A claimed aligned `dmarc=fail` or `dkim=fail` in any such header, a contradictory or planted header, rejects the message; only the owner can record it anyway, audited. Any movement that is not verified pays nothing by itself: a person pairs it only after confirming it in their internet banking, which the application enforces. The model, and what the operator must configure, is in [docs/mail.md](docs/mail.md#who-sent-it), [docs/bank-pairing.md](docs/bank-pairing.md#the-security-model) and [docs/adr/0020-bank-notification-mail.md](docs/adr/0020-bank-notification-mail.md).

A way to make a bank message pay an invoice without a verified notice from the bank is a vulnerability; please report it as below.

## Verifying a release

From the first release after v0.11.11 on, every [GitHub release](https://github.com/foresi/foresi/releases) carries three files built by the Release workflow from the tagged commit:

- `foresi-VERSION.tar.gz`: the source of the release (`git archive` of the tag).
- `foresi-VERSION.cdx.json`: its software bill of materials in CycloneDX 1.6: the Composer packages of `composer.lock` without the development ones, and the JavaScript packages of `importmap.php`.
- `foresi-VERSION.sigstore.json`: the signed build provenance of both files (a GitHub artifact attestation, signed keyless through Sigstore).

Check that a file was built by this repository's Release workflow from the release's commit:

```bash
gh attestation verify foresi-VERSION.tar.gz --repo foresi/foresi
```

The provenance meets SLSA v1.0 Build Level 2: the build runs on a GitHub-hosted runner, and GitHub signs the provenance. It is not Level 3, because the build does not run in an isolated reusable workflow. Composer installs Foresi from Packagist, which serves GitHub's archive of the tag, not these files, and Composer checks no attestation today. The provenance and the SBOM tell you what a release contains and where it was built; they do not change what `composer install` checks.

## Reporting a vulnerability

Please do not report security problems in public issues, discussions or pull requests.

Use GitHub private vulnerability reporting: open the **Security** tab of this repository and choose **Report a vulnerability**. This is also the private contact for the Foresi maintainers, including for Code of Conduct reports.

Please include:

- a description of the problem and its impact
- steps to reproduce, or a proof of concept
- the affected version or commit

You can expect an acknowledgement within a few working days. We will keep you informed while the problem is investigated and fixed, and we will credit you in the release notes if you wish.
