# Security policy

## Supported versions

Foresi is under active development and has no stable release yet. Security fixes are applied to the default branch.

## Inbound surface

Most of Foresi is behind sign-in. These parts take input from outside it:

- **Public document links** (`/d/<token>`): a 256-bit token, stored only as a hash, rate limited per IP. See [docs/sending.md](docs/sending.md#the-public-link).
- **Bank e-mail.** Mail to a bank account's inbound address, taken from the operator's mailbox over IMAP or posted to the optional webhook `POST /inbound/mail`. The address carries a 128-bit token, stored only as a hash and a sealed copy. The webhook needs a bearer secret (`INBOUND_WEBHOOK_SECRET`; empty turns it off), is rate limited per IP before the secret is checked, takes at most 10 MiB and answers alike for known and unknown addresses. A bank message pays an invoice only when the topmost `Authentication-Results` header comes from a receiving server the operator trusts (`BANK_INBOX_TRUSTED_AUTHSERV`) and shows a single aligned `dmarc=pass`, or the strict ARC rescue applies. A claimed aligned `dmarc=fail` or `dkim=fail` in any such header, a contradictory or planted header, rejects the message; only the owner can record it anyway, audited. Any movement that is not verified pays nothing by itself: a person pairs it only after confirming it in their internet banking, which the application enforces. The model, and what the operator must configure, is in [docs/bank-pairing.md](docs/bank-pairing.md#the-security-model) and [docs/adr/0020-bank-notification-mail.md](docs/adr/0020-bank-notification-mail.md).

A way to make a bank message pay an invoice without a verified notice from the bank is a vulnerability; please report it as below.

## Reporting a vulnerability

Please do not report security problems in public issues, discussions or pull requests.

Use GitHub private vulnerability reporting: open the **Security** tab of this repository and choose **Report a vulnerability**. This is also the private contact for the Foresi maintainers, including for Code of Conduct reports.

Please include:

- a description of the problem and its impact
- steps to reproduce, or a proof of concept
- the affected version or commit

You can expect an acknowledgement within a few working days. We will keep you informed while the problem is investigated and fixed, and we will credit you in the release notes if you wish.
