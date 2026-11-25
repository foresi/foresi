# 47. Bank statement intake

* Status: accepted
* Date: 2026-11-25
* Amends: [ADR 0020](0020-bank-notification-mail.md) (bank mail is one channel of the intake), [ADR 0044](0044-public-extension-contract.md) (module API 1.2)

## Context and problem statement

Until now bank movements reached Foresi only as bank mail, read by a `BankMessageParser`. Banks also give statements as files (camt.053, MT940, ABO/GPC in Czechia) and through APIs (Fio, aggregators). Each of those channels would otherwise bring its own path to record a movement, its own idea of trust and its own duplicates. Mail and a statement also tell the same payment twice: the notice comes on the day, the statement later. And mail has no balances, so Foresi could not notice that a statement is missing, which a statement file can show by its numbers and balances.

## Decision drivers

* One place decides trust, duplicates, recording and pairing, whatever the channel.
* A module adds a format or a bank connector without touching Banking.
* A payment told by two channels is one movement; what the bank said first is never rewritten.
* A missing or truncated statement is seen, but never stops the movements from being recorded.
* Nothing is designed before it is needed: no connector screen until a second connector exists.

## Considered options

* One tagged reader port for every channel, turning bytes into statements; API connectors fetch the bytes themselves
* One inbound service, `StatementIntake`, plus a tagged `StatementFormat` port for files (chosen)
* One tagged "source" port with pull and push methods for mail, files and APIs

## Decision outcome

### The ports

`Foresi\Banking\Contract\StatementIntake` is a service Banking provides: `submit(BankStatement, MovementProvenance, ?StatementFile): IntakeResult`. The upload of a file, a connector's scheduled job and Banking's own mail path all submit to it; behind it sit trust, deduplication, corroboration, recording, pairing and the gap check, once. `StatementFormat` (tag `foresi.banking.statement_format`) reads a `StatementFile` into a list of `BankStatement`s and fails with the existing `ParseFailed`. A bank API connector is a module of its own with a `ScheduledJob` that calls the intake; it gets no tag until a second one shows what a common screen needs. `BankMessageParser` stays: a mail parser is the format of the mail channel, and `ProcessMessage` hands its movements to the intake, one statement without balances per account.

The reader port was rejected because API connectors have credentials, cursors and paging of their own and would end up as readers that also fetch. The source port was rejected because it forces every implementation to stub what it is not, and the core would schedule APIs it knows nothing about.

The values are in `Contract\`: `BankStatement` (account, currency, entries as `ParsedMovement`s, and optionally the bank's statement id, a `StatementSequence`, the period and signed opening and closing balances), `StatementFile`, `MovementProvenance` (channel, source id, trust, basis, the message or the uploading user) and `IntakeResult`. The trust scale moved from `Domain\MessageTrust` to `Contract\MovementTrust`, as every channel uses it; Domain is internal, so the old name was not kept as an alias.

### Trust travels with the provenance

The channel that knows how makes the verdict, and the provenance has a named constructor per case: mail keeps its rules (DMARC, the ARC rescue), a connector that reached the bank's own API over TLS with the firm's credentials is Verified (`bank-api`), a file a staff member uploaded is Verified (`uploaded`: the same person could pair by hand, and the statement keeps who and the SHA-256 of the file), and a module that cannot vouch is Unverified (`unverified-source`). Any installed module may claim Verified: installing a module is the operator's trust in it, as with any code. A Failed provenance is refused; a failed mail is rejected before its movements reach the intake.

### Duplicates and corroboration

Every movement records its channel and source. An entry whose bank reference the same source gave before, first or as the corroborating one, is skipped as known. Without a reference, the fingerprint rule of bank mail applies within the source: an equal entry of another submission is a possible duplicate. The same file of the same source again is taken once, so an upload twice changes nothing.

An entry of another source corroborates a movement instead of adding one when exactly one movement of the account has the same direction, amount, booked day and variable symbol (and counter-account when both state one), is not a possible duplicate and was not corroborated yet. The movement keeps what the bank said first and gains the corroborating source, reference, statement or message and time (audited); its trust rises when the new source is trusted more, never falls, and a movement that waited for being unverified is put through the automatic pairing again. Two or more candidates make the entry a possible duplicate for a person.

### Gaps

A statement of any channel but mail is stored in `bank_statement`: account, channel, source, statement id, sequence, period, balances, the net and count of its entries, trust, who uploaded it, the file's SHA-256 and name. The chain is derived, never stored, as Odoo checks a statement's opening balance against the previous closing one. A chain is the statements of one account **and one source**, ordered by period end, number and arrival. This refines the design, which chained per account: a daily API and a monthly file of one account cover the days differently, so their statements are not neighbours, and comparing them would warn every day. Between neighbours a number missing is a number gap, else an opening balance that is not the previous closing one is a balance gap; inside a statement, entries that do not add up from opening to closing are an inner mismatch. A statement of the number of the one before is that statement again, corrected, and takes its place. A gap is a warning only. The intake compares the gaps before and after each statement and records `banking.statement.gap_detected` or `banking.statement.gap_closed` (version 1) for each one opened or closed.

The shell shows a notice while an account's statements do not follow on, and the bank screen a Statements tab with the gap under the statement after it, in Odoo 17's words ("…or an earlier statement is missing").

### The module API

New interfaces, values, events and a tag raise the module API to 1.2.0.

### Consequences

* Good, because a format or a connector is a small module: it reads or fetches, and the intake does the rest the same way for every channel.
* Good, because the notice and the statement of one payment are one movement, and the statement can raise the trust of an unverified notice so that it pays.
* Good, because a missing or truncated statement shows on the bank screen, on every page and as a webhook event.
* Bad, because corroboration matches on the amount, day and symbol: two equal payments on one day stay possible duplicates for a person.
* Bad, because the gap check reads every statement of an account and source on each submission and on each page with the notice; at a statement a month per account this is small, and an index on account and period keeps it so.
* Bad, because no format, upload screen or connector ships yet: the port is proven by a test format only (camt.053 and ABO/GPC with an upload dialog, and the Fio connector, are later steps).
