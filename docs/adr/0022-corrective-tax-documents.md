# 22. Corrective tax documents, refunds and their balances

* Status: accepted
* Date: 2026-11-05

## Context and problem statement

An issued tax document cannot be edited once the customer has it. When the price of a supply changes after it, or the document was wrong, a VAT payer issues a corrective tax document (§42 of the Czech VAT Act for a change, §43 for an error) within 15 days of the fact (§28(1)(b), §45). The correction names the document it corrects, the reason and the difference in base, tax and total per rate (§45). The same applies to a tax document on a received payment whose payment was cancelled or refunded. A credit note lowers what the customer owes, and when the customer paid more than that, the firm refunds the difference. Corrections must not break payments, pairing, reminders, the export or the ISDOC file, and two people must never credit more than was invoiced.

## Decision drivers

* One kind and one path for every correction, credit and debit alike.
* What is left to pay always follows the documents and the money, never a stored state.
* Every rate and treatment, partial credits, several corrections of one invoice and partial payments work, not one 21 % line credited in full.
* The cap holds under concurrent saves, on SQLite and PostgreSQL alike.
* The ISDOC file states what the document is, so an accountant's import needs no guessing.

## Considered options

* Kinds: one kind for both directions, signed by its total (chosen); a credit note and a debit note as two kinds
* Where the link lives: on the correction, which names its original (chosen); a link table
* The cap: per rate and treatment across all corrections, checked again under a lock (chosen); on the total only; none
* A refund: a payment with a direction, its allocation below zero (chosen); a negative invoice; a payment outside the invoice
* A tax document on payment whose payment goes back: corrected in the same step (chosen); listed for a person only

## Decision outcome

**One kind.** `DocumentKind::CorrectionDocument` is a tax document a person issues; it is not payable itself. Its total decides what it is: below zero it is a credit note (dobropis), above zero a debit note (vrubopis). Its standard series is `O7{YYYY}{NNNN}`, so its variable symbol never collides with an invoice, an advance or a tax document on payment. It has its own mail template, with the number of the original among its variables.

**Links.** A correction stores `correctedInvoiceId`, the reason, the type (`change` under §42, taxed on its decisive date; `error` under §43, taxed on the tax point of the original) and the decisive date. It corrects an issued invoice in place, or a delivered tax document on payment, of the same firm and customer. It takes the rates and treatments of the original, priced on the original's rate date, and deducts no advances. An edit keeps the link. Every document of the chain shows it.

**The cap per rate under a lock.** Per rate and treatment, the original and its corrections in place never go below zero. `CorrectionRules` checks it when the form is read, and `IssueInvoice` checks it once more inside the write transaction after locking the row of the original, so two credits saved at once cannot both pass on the same remainder. A trashed correction frees its amount; a debit note makes room for a later credit.

**Balances.** What is left to pay on an invoice is its payable plus the payables of its corrections outside the trash, less incoming payments, plus refunds. A fully credited unpaid invoice is settled and offers no Pay. An invoice paid above what is now due is to refund ("K vrácení"), with a tab of its own. A correction is never owing, overdue, reminded or paired itself. A credit note asks for no payment: its PDF, link page and send carry no account, no due date and no QR code. A debit note asks for payment like an invoice.

**Refunds as negative allocations.** A payment has a direction, incoming or refund. A refund is recorded by hand from the invoice's payment panel, filled in with what is to refund; more is refused under the lock, and a refund writes nothing off. Its allocation is stored below zero, so every balance that sums allocations follows it unchanged. An outgoing bank movement can be paired by a person with an invoice that has something to refund, by the invoice's variable symbol or its credit note's, then by amount; it never pairs by itself. Cancelling a refund works as cancelling a payment, and its movement goes back to recorded.

**The automatic type 6 correction.** Cancelling or refunding a payment of an advance corrects its delivered tax documents in the same transaction, at a savepoint: the correction negates what is left of the document, split over its rates as the receipt was, at the rates and exchange rate of the receipt, dated on the day of the cancellation or refund. Refunds go back on the latest payments first. A tax document the customer never got goes to the trash instead, and one a part refund leaves partly paid is reissued for what stays paid. A document a final invoice deducts is refused until the deduction is released; it and any refused correction wait on the "Waiting for correction" tab with days left to the 15-day deadline. A person can also correct a delivered tax document by hand.

**ISDOC.** A credit note of an invoice is `DocumentType` 2, a debit note 3, and a correction of a tax document on payment 6. Each carries an `OriginalDocumentReference` with the number, issue date and UUID of the original. The amounts are the document's own signed amounts; neither the schema nor the Schematron fixes a sign convention, so check the import with your accounting program. The file is validated against the 6.0.2 schema in the tests. *Superseded by appendix A.6 of ISDOC 6.0.2 (Finanční částky), positive amounts:* the amounts are positive, and a credit note (type 2) and a correction of a tax document on payment (type 6) are always in positive amounts, the type saying that they subtract; a debit note (type 3) is positive as stored. A line against the direction of its document stays negative, with a positive quantity and a signed unit price. The PDF still prints the signed differences.

**Out of scope.** Settling a credit note by offset against other invoices of the customer; a correction for a rate change between an advance and its final invoice; the correction of tax on bad debts (§46); ISDOC types 4 and 5.

### Consequences

* Good: one form, one cap and one balance rule serve credit and debit notes; a credit can never exceed what was invoiced, whatever the order of saves.
* Good: a cancelled or refunded advance payment is corrected without a click, and a case the rules refuse is visible with its deadline.
* Good: refunds reuse payments, pairing and history; nothing stores a balance that could drift.
* Bad: a credit note settles only its own invoice; an offset against another invoice is recorded as a refund and a payment by hand.
* Bad: the sign convention of the ISDOC amounts is unconfirmed until an accountant's import checks it. (Superseded: appendix A.6 of the standard fixes positive amounts, see ISDOC above.)
