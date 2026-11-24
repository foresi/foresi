# 43. Mail module

* Status: accepted
* Date: 2026-11-24
* Amends: [ADR 0020](0020-bank-notification-mail.md), whose intake moves out of Banking

## Context and problem statement

[ADR 0020](0020-bank-notification-mail.md) built the receiving of mail inside Banking: the operator mailbox, the webhook, the secret address per bank account, the raw store, the check of the sender and the dedup. Every part but the bank parsers is not about banks. More modules need mail addressed to them: a person who forwards a message to a Foresi address should have it reach their own inbox, and an invoice from a supplier should reach purchasing. Copying the intake into each module would copy a security-relevant path (untrusted bytes, secret tokens, trust in headers), and a module that read Banking's tables would break the rule that modules talk only through contracts.

## Decision drivers

* One security-relevant intake, reviewed once.
* Modules talk only through `Contract/`; Banking must not know how mail is stored, and Mail must not know banks.
* The first step is a refactor: bank behaviour, screens and addresses stay as they are, and nothing that was paired or stored is lost.
* A bank notice that could not be told from a forged one still pays nothing.
* A change to an installation's settings must not stop mail on upgrade.

## Considered options

* Where the mail lives: a Mail module that Banking depends on (chosen); a generic intake that stays in Banking; an intake per module.
* The stored message: a new table `mail_message` with the generic columns, and `inbound_message` keeping only Banking's state (chosen); the old table renamed into Mail with the bank columns; the old table left in Banking and a second one for other purposes.
* Addresses: the address table in Mail from the first step, so Banking routes through it from day one (chosen); Banking keeps routing through its own directory behind a port until a later step.
* The hand-over: Mail calls the purpose's own `accept()` inside the receiving transaction (chosen); Banking listens to an event.
* Showing an address again: a purpose declares whether its address can be shown again, and only then is a sealed copy kept (chosen); every address is sealed; no address is ever shown again.

## Decision outcome

### Mail is a module that owns the intake

A new module, Mail (`foresi/mail`), owns the mailbox poll, the webhook `POST /inbound/mail`, the inbound addresses, the raw store, the dedup, the sender verdict and the contracts. Banking requires Mail and uses only `Foresi\Mail\Contract`. Mail knows no bank and depends on no other module's domain. What the mail is for stays with the module that owns the address.

### A message table in Mail

`mail_message` holds what every message has: its address, channel, dedup key, raw key and hash, size, threading headers, sender, subject, dates and verdict. Banking's `inbound_message` keeps its processing state (status, trust, parser, failure, attempts, its feed) and a `mail_message_id`. One migration copies each existing row's generic columns into `mail_message` **with the same id**, so the raw files, the movements and every URL stay valid. The raw files do not move. The two tables are linked by id, not by a foreign key, as a foreign key would make Banking's mapping depend on a Mail domain class.

### Addresses live in Mail from the start

`mail_inbound_address` holds the token (hashed, with an optional sealed copy), its purpose, an optional owner, a label and its revoked and last-received dates. Each existing bank feed gets an address row with the same token hash, so an address handed out before the move still routes after it. Banking creates, rotates and reads its feed address through `AddressIssuer`. Doing this in the first step means the bank routing changes once.

### The purpose takes the message

Mail calls `InboundPurpose::accept(messageId, addressId)` inside the transaction that stores the row, as the receiving did before. Banking's purpose, `bank_feed`, creates its `inbound_message` row and queues `ProcessInboundMessage`, so the timing of the bank intake does not change. A public event for other listeners is a later step. An exception from `accept()` rolls the row back and removes the raw file; a purpose that does not want the message throws `NotAccepted` and the message is treated as for an unknown address (an archived bank account still drops its mail, as it did). An address whose purpose key has no registered consumer drops the mail unread and stores nothing.

### Only some addresses are shown again

The bank address stays re-showable, as the firm reads it again in the bank settings. A purpose says so (`reshowable`), and only then does Mail keep a sealed copy; `reveal()` returns nothing for any other purpose. Addresses meant to be shown once, as calendar feed addresses are, will not keep a copy.

### One verdict, a policy per module

The verdict algorithm (the topmost `Authentication-Results` of a trusted server, a single aligned `dmarc=pass`, the strict ARC rescue, a claimed `fail` rejecting) moves to Mail whole, unchanged, with the sender's allowed domains as a parameter. An empty list means the domain of the `From` header, which is what Mail stores on every message: `verified`, `failed` or `unknown`. Banking asks for the verdict with the domains of the bank, and keeps its own policy on top: what a verdict means for paying an invoice, and the "accepted by the owner" state. A generic verdict never decides a payment.

### Settings

The environment variables `BANK_INBOX_*` become `MAIL_INBOUND_*`, one for one, since they name Mail's mailbox, addresses and trusted servers, not the bank's. `INBOUND_WEBHOOK_SECRET` and `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` keep their names, as does the parameter `app.inbound_mail_dir` and the two rate limiters. For one release a setting is read from its old name when the new one is empty, with a deprecation warning once per setting and process that never contains a value, so an upgrade cannot silently turn mail off. The operator task `bank-inbox` becomes `mail-inbox`.

### Consequences

* Good, because the one intake that handles untrusted mail is reviewed and tested in one place.
* Good, because the next consumers of mail need a purpose, not a copy of the intake.
* Good, because bank behaviour, addresses, files and links are unchanged, and a test pins that an address from before the move still routes.
* Bad, because two tables describe one bank message, linked by id with no foreign key, until a later step simplifies it.
* Bad, because an installation must rename nine settings within a release, though nothing breaks before that.
* Bad, because messages stored before the move have no threading headers and an `unknown` generic verdict; their bank trust is unchanged.
* Bad, because dedup is per address, so the same mail sent to two bank accounts is now stored twice.
