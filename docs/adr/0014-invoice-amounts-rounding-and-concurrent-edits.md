# 14. Invoice amounts, rounding and concurrent edits

* Status: accepted
* Date: 2026-11-01

## Context and problem statement

An invoice is a legal document: the amounts on the screen, in the PDF and in the ISDOC file must be the same, must not change after the fact because a rule or a rate changed, and must be right when two people edit the same invoice. Rules about VAT, rounding and the right to issue documents are subject to professional tax and legal review.

## Decision drivers

* One source for every figure, so that the screen, the PDF and the ISDOC file cannot disagree.
* An old document keeps its figures when the rules change.
* Money is exact (ADR 4); no floating point, no hidden rounding.
* Two simultaneous edits must not corrupt lines or totals, on SQLite and PostgreSQL.
* The software refuses what it cannot produce correctly.

## Decision outcome

### The invoice is the source of the amounts

* The amounts of a line (unit price after discount, net, VAT, gross) are calculated once, when the invoice is saved, and stored on the line together with the VAT rate and tax treatment that applied.
* Everything that shows an issued invoice reads what is stored. The summary per rate is the sum of the stored line amounts. Nothing is priced again, so a document issued under older rules keeps its figures when the code or the rates change.
* The issuer, the customer and the VAT regime are frozen on the document when it is issued (see ADR 10).

### Calculation and rounding

* Prices, quantities and discounts are exact decimals; one rounding applies to price, quantity and discount together, to the minor unit (the haler).
* VAT is not rounded to whole crowns. From a net price, VAT is the net times the rate, rounded to the minor unit. From a gross price, the net is the gross divided by one plus the rate, rounded to the minor unit, and VAT is what is left.
* The total can be rounded to a whole unit, as a setting of the firm. This applies only to an amount in Czech crowns in the home currency, and the difference is a separate amount outside every tax base and every VAT amount. The payable total is the gross total plus the rounding. In any other currency the setting does nothing.
* The PDF shows the rounding as its own row; the ISDOC file carries it as `PayableRoundingAmount` (ADR 12). The QR payment code carries the payable total.
* A firm that is not a VAT payer issues documents without VAT: any rate is removed before calculation, also when an invoice is recounted.
* The calculation was checked against an independent recalculation of random documents. Whether each rule, in particular for the reverse charge and for the rounding, is right for a given business is subject to professional tax and legal review.

### An identified person is refused

* A firm whose VAT status is "identified person" cannot issue documents yet. The save is refused with a message that says the rules for it are under review. No such firm is planned, and the refusal stays until the rules for it have been reviewed by a tax adviser. A firm can still be recorded with this status.

### Concurrency: lock and version

* Every change to an invoice first takes a lock on it. The lock is a write that changes nothing (`UPDATE ... SET version = version`), because `SELECT ... FOR UPDATE` does not exist on SQLite and a transaction that reads first fails when it starts to write there. On PostgreSQL the same statement takes the row lock. Rules are checked inside the lock, so what they check stays true until the save ends.
* The form carries the version of the invoice that it was opened on. If the stored version is different when the save runs, nothing is changed and the user is told that the invoice was changed meanwhile. A double-clicked Save reaches the same answer rather than saving twice.
* Lines have a unique position per invoice, so that a race cannot leave two lines at one place.
* A save that loses to another one is repeated from loading (ADR 13).

### Consequences

* Good: the screen, the PDF and the ISDOC file show the same amounts, always.
* Good: no change of rules rewrites an old document.
* Good: concurrent edits are serialised on both databases and a stale form cannot overwrite a newer save.
* Bad: a mistake in a stored amount has to be corrected by editing the invoice; there is no recalculation of old documents by design.
* Bad: identified persons cannot issue invoices until their rules are reviewed.
* Bad: a user who loses a race sees a conflict message and must reopen the invoice.
