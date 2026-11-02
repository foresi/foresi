# 20. Bank notification mail

* Status: accepted
* Date: 2026-11-02

## Context and problem statement

Banks such as Komerční banka mail a notification for every payment on an account. Foresi should turn these into bank movements and pay the matching invoices, on a self-hosted installation with one small host, without a bank API. Mail is easy to forge: anyone can send a message that looks like the bank's, with any headers. A forged notice that pays an invoice would let a customer mark their own debt as settled, so a wrong "paid" is worse than a missed one. Banks change the layout of their mail without notice.

## Decision drivers

* A forged or unclear message never pays anything; it stays visible.
* Nothing to run beside the worker that sending already needs.
* One inbox for the installation, routed to the right organisation and account.
* A layout change fails visibly and is read again after a fix; never a wrong amount.
* Country and bank specifics stay in the country module; the core uses English terms.
* No dependency the project cannot keep maintained.

## Considered options

* Inbox channel: an operator mailbox polled over IMAP, and an optional webhook for a relay (chosen); an SMTP receiver in Foresi; IMAP only
* IMAP library: `directorytree/imapengine` (chosen); `webklex/php-imap`; `ddeboer/imap` on `ext-imap`
* Trust: the receiving server's topmost `Authentication-Results`, verified only by a single aligned `dmarc=pass` or a strict ARC rescue (chosen); an aligned `dkim=pass` alone; a pass that outweighs a fail; DKIM verification of our own
* Unverified mail: recorded and suggested, never paired by itself, paired by a person only after confirming it in the bank (chosen); one-click pairing with the invoice filled in; paired like verified mail; dropped
* Routing: a secret token per account in a plus address (chosen); one address per organisation; matching on the sender

## Decision outcome

### The banking module and its contracts

A module `src/Banking` holds the inbound addresses, the message log, bank movements, the trust verdict and the matcher, in its own Domain, Application and Infrastructure layers. Banking may use Party, Invoicing Application and Domain, Tenancy, Audit and Shared; Invoicing and Party never use Banking, which plugs into them through the ports they declare (`BankAccountUsage`, `PaymentSourceRelease`). Deptrac enforces this, and confines `Zbateson\` to Banking Infrastructure and `DirectoryTree\`, `Illuminate\` and `Carbon\` to `Banking\Infrastructure\Imap`.

A bank is a `BankMessageParser`: `id()`, `version()`, `senderDomains()`, `supports(InboundMail)` and `parse(InboundMail): ParseResult`. A result is a list of `ParsedMovement`s (direction, amount in minor units, own account as IBAN, counter-account, name, date, symbols, message, bank reference) or `ignored(reason)` for a notice that moves no money. A parser fails only with `ParseFailed` and a typed `ParseFailure`: UnknownLayout, MissingField, InvalidValue, Inconsistent or Ambiguous. Two parsers supporting one mail is Ambiguous. The parser owns its sender allowlist. Parsers are tagged services, and the Komerční banka parser lives in `src/Country/CzechRepublic/Banking`, with the Czech labels as its data.

`MailReader` is the one port over MIME (`zbateson/mail-mime-parser`): headers in message order, the sender, both bodies decoded to UTF-8, attachment names and the hash of the raw bytes.

A message keeps its raw form, its parser id and version, and its failure. The hourly job `bank-reparse` reads again the failed messages whose parser's version went up, and the not-bank messages when the set of parsers changed. A layout guessed wrong therefore costs no data: it fails as UnknownLayout, and the fix with a raised version picks it up.

### The inbound address

One address per bank account, generated on request: the operator's template `BANK_INBOX_ADDRESS` (such as `bank+{token}@example.com`) with a token of 128 random bits as 26 characters of lowercase base32. The database keeps the token's SHA-256 for the lookup, unique, and a copy sealed with the keys of `MAIL_SECRET_KEYS` for display, excluded from the audit log. Generating again replaces the token, and the old address stops at once. The token is the only secret: the bank's sender address is public and cannot route.

The token is read from the first of `X-Foresi-Recipient` (webhook only), `Delivered-To`, `X-Original-To`, `Envelope-To`, `X-Envelope-To`, `X-Forwarded-To`, `To` and `Cc` holding an address of the template, so a forward that keeps the original `To` still routes. The lookup crosses tenants through the tenant filter bypass and returns scalars; nothing is kept of mail for no known token. A notice about another account of the same firm is accepted on any of that firm's addresses, because KB+ sends to one address only; an account of no firm of the address rejects the message.

One address per organisation was rejected: a firm with two accounts would have no way to tell them apart before the parser, and rotating one would break all. Routing on the sender was rejected: every customer of a bank shares it.

### The inbox

One operator mailbox, set by `BANK_INBOX_*`, is polled by an operator task (`bank-inbox`, every five minutes, under a lock, no tenant) on the existing `foresi` schedule: the 50 oldest messages, read without marking them read, then **moved** to `Foresi/Done`, `Foresi/Unknown` or `Foresi/Rejected` under the server's personal namespace, never deleted and never expunged. A message whose handling failed stays and is taken again, five times at most: then it is moved to `Foresi/Rejected`, and one the server will not move is left out of later batches, so the poller never stalls behind bad messages. Without `MOVE`, a copy and a `\Deleted` flag move it; a crash between storing and moving costs nothing, as the stored message is found again by its dedup key, the SHA-256 of its `Message-ID` or else of its bytes.

An optional webhook, `POST /inbound/mail`, takes the raw message from a relay, with `INBOUND_WEBHOOK_SECRET` as a bearer token compared in constant time, the secret checked first, failed checks limited to 120 a minute per IP and requests with the secret to 600 a minute per IP, so strangers sharing the relay's address cannot lock it out, a 10 MiB limit, and 202 for stored, duplicate and unknown recipient alike, so it is no oracle for addresses. An empty secret answers 404.

Raw mail is kept under `inbound-mail/{tenant}/{id}.eml` in the share directory, with its SHA-256 checked on every read, and processed in the `async` queue, one transaction per message that starts by claiming it.

An SMTP receiver in Foresi was rejected: it needs a public port 25, a TLS certificate, spam handling and a second long-running process, everything an operator's mail service already does, and it would make Foresi the receiving server whose word the trust rule relies on. `webklex/php-imap` was rejected: no release since April 2025. `ddeboer/imap` was rejected: it needs `ext-imap`, which left PHP core for PECL in PHP 8.4. `directorytree/imapengine` is pure PHP and maintained; it brings `illuminate/collections` and `nesbot/carbon`, which deptrac keeps inside one adapter behind the `InboundMailbox` port. It lacks the IMAP `NAMESPACE` command, which a small subclass adds.

### The trust rule

Foresi checks no signature itself. It reads **only the topmost `Authentication-Results`**, which the receiving server of the bank inbox adds above every header the message arrived with, and only when its authserv-id is listed in `BANK_INBOX_TRUSTED_AUTHSERV`. A message is **Verified** only when that header holds exactly one DMARC result, a `dmarc=pass` whose `header.from` the sender aligns with and which is a domain of the bank, and no lower header carries a trusted authserv-id. The strict ARC rescue also verifies: `arc=pass` in the trusted header, the newest ARC set sealed by a domain in `BANK_INBOX_TRUSTED_ARC`, and an aligned `dkim=pass` in that set's results. It overrides a signature broken by a forwarder, never a `dmarc=fail`.

**Fail closed.** A claim of an aligned `dmarc=fail` or `dkim=fail` counts from **any** `Authentication-Results` header, trusted or not, and gives Failed: a fail claim can only make the verdict stricter, so honouring it gives a forger nothing. The rescue overrides a broken signature only in the trusted topmost header. Also Failed: a pass and a fail for one signing domain, two DMARC results (both `ambiguous`), and a trusted id on a lower header, which a receiving server removes or renames (RFC 8601 §5), so it was planted or misplaced (`planted-results`). A `From` outside the bank's domains, or with two addresses, is Failed. No trusted header, or no verifying result, is Unverified.

The operator must make the inbox's receiving server add its own `Authentication-Results` at the top of every message, list its authserv-id, and list ARC sealers, including the inbox provider itself when it seals with ARC on receipt. The setup is in [bank-pairing.md](../bank-pairing.md#what-the-operator-must-do).

Rejected alternatives:

* **An aligned `dkim=pass` alone verifies.** A receiving server copies text the sender chose, such as the HELO name or the envelope sender, into its header, not always quoted, so a sender can make the trusted header seem to hold a `dkim=pass header.d=kb.cz` the server never wrote. Komerční banka publishes a DMARC policy for `kb.cz` and `kbinfo.cz`, so an honest server always writes a DMARC result for its mail; requiring exactly one, and a pass, leaves a smuggled result nothing to stand on.
* **A pass outweighs a fail** (the first rule: Failed only on an aligned fail with no pass). With smuggled text, a header can hold both a real fail and a planted pass. A pass and a fail for one signer, or two DMARC results, is a header no honest check writes, so nothing verifies then.
* **DKIM verification of our own.** No maintained PHP verifier exists: only `angrychimp/php-dkim` and `pimlie/php-dkim` turned up, with about 4,600 installs each. Writing one would mean DNS lookups, canonicalisation and key handling the project cannot review to the standard a payment needs, and it would still not see what the receiving server saw, such as SPF. The receiving server already does this work.

**Unverified mail is recorded and suggested, never paired by itself; a person confirms it in the bank.** Pairing it like verified mail was rejected: an installation without a trusted receiving server would then pay invoices on anyone's word. Dropping it was rejected: a forwarding setup that loses DKIM without ARC would lose real payments silently. A one-click pairing with the invoice ticked and filled in was rejected too (decided 2026-11-02 after the security review): it turns a forged notice into a routine-looking click. The pair dialog of a movement that is not Verified warns that Foresi did not verify it with the bank, ticks and fills in nothing, and needs the box "I checked the payment in the bank"; `PairMovement` refuses the pairing without that confirmation (`not_checked_in_bank`), so no caller skips it. A Failed message is rejected without a movement; only the owner can "Record anyway", after a warning that it may be forged, the audit log keeps who did and when, and never for an account that is not the firm's.

### Consequences

* Good: a forged notice pays nothing, and every refusal is visible with its reason; one inbox serves any number of organisations; a layout change is fixed once and picked up again by itself; no new process to run.
* Bad: the operator must configure the receiving server and the trusted ids correctly, or nothing verifies, which looks like broken pairing rather than a security choice. Polling adds up to five minutes of delay. The raw messages hold personal data and must be backed up with the database.
* The incoming Komerční banka layout is assumed to mirror the outgoing one until a real incoming notice confirms it; a wrong guess fails visibly.
