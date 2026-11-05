# 21. Advances, tax documents on payment and their settlement

* Status: accepted
* Date: 2026-11-05

## Context and problem statement

A firm asks for payment before it supplies: an advance invoice. Under the Czech VAT Act a VAT payer who receives such a payment for a known supply owes VAT on the day of receipt and issues a tax document within 15 days (§20a, §28(1)(d), §28(8)); for a supply whose place is abroad, within 15 days of the end of the month (§28(9)(d)). The final invoice then deducts the advances, rate by rate (§37a). The documents must stay linked, a payment must never be taxed twice, an advance never deducted twice, and none of it may disturb payments, pairing, reminders or the accountant's export.

## Decision drivers

* One path for every payment: by hand and from the bank alike.
* A missed deadline is visible; a tax document that cannot be issued never blocks a payment.
* Every rate and treatment works, partial payments and overpayments included, not one 21 % line paid in full.
* The kind of a document decides what applies to it, never its series or number.
* Unique facts are kept by the database, on SQLite and PostgreSQL alike.

## Considered options

* What creates the tax document: a person (iDoklad), every payment (Fakturoid), every payment with a list of those still waiting (chosen)
* How a final invoice shows advances: negative lines per tax document and rate (chosen), an ISDOC-style block per rate, one last line
* What a deduction is: an advance, or a tax document on payment (chosen)
* Where an advance's lack of a tax point lives: a nullable tax point (chosen), the issue date stored as tax point

## Decision outcome

**Kinds.** `DocumentKind` has Invoice, Advance and PaymentTaxDocument. A document takes its kind from its series, and every path that accepts a series checks the kind: issue, edit and renumber, recurring templates and the form. Each firm has one default series per kind; the standard ones are `{YYYY}{NNNN}`, `Z9{YYYY}{NNNN}` and `D8{YYYY}{NNNN}`, so their variable symbols never collide. The kind answers `isPayable()` (invoice, advance), `isTaxDocument()` (invoice, tax document) and `isIssuedByPerson()` (invoice, advance).

**Advance invoice.** An `Invoice` row of kind Advance with a NULL tax point; its rates are those of its issue date. Its PDF says it is no tax document under the VAT recap, its QR code carries no tax fields, and it has no ISDOC file. It is paid, paired, listed and, with the firm's switch, reminded like an invoice.

**Whether VAT arises on receipt.** `AdvanceTaxation` decides per advance: none from a firm that is no VAT payer; none when a person said the supply is not known yet; a person may ask for the documents; none for a customer who is a private person; none when every line is a domestic reverse charge; otherwise VAT arises, with the month-end deadline when every line is a reverse charge to a customer of another country.

**Tax document on payment.** `RecordPayment::record`, the one path of every payment, issues it through `IssueInvoice` with `PaymentTaxDocumentOrigin`, in the payment's transaction at a savepoint of its own. The amount received is split over the groups of the advance in proportion of their gross amounts, each line priced with VAT so the VAT comes out of the gross (§37). Its tax point is the day of receipt. It names its advance and payment; the pair is unique. A rule that refuses it leaves the payment standing, and the advance appears in the list of paid advances without a tax document, with days left, where a person issues it. It is paid by its payment: never paired, reminded or overdue, and a payment of it is refused.

**Cancelled payment.** An undelivered tax document of it goes to the trash; a delivered one stays and asks for a corrective tax document, which a later step brings.

**Settlement.** A final invoice deducts a tax document with one negative line per line of it, priced with VAT, and a paid advance on which no VAT arose with one negative line outside VAT. A deduction line names what it deducts; the deducted document names the one invoice that deducts it, claimed by a conditional update in the saving transaction, so two invoices saved at once never deduct it twice. Trashing the invoice gives its claims up; restoring takes them again or is refused. A payable of zero or less is allowed only with deductions, and such an invoice reads as settled by advances. Money stays allocated to the advance, so nothing is counted twice. The ISDOC file of the final invoice carries the supply as lines, the tax documents as `TaxedDeposits` per rate with `AlreadyClaimed*` and `Difference*` per rate, and advances without VAT as `NonTaxedDeposits` and `PaidDepositsAmount`.

### Consequences

* Good: a bank payment of an advance is taxed without a click; a refused document is visible with its deadline; the database refuses a second tax document per payment and a second deduction per document.
* Good: an ISDOC import of the final invoice sees the deposits, while the PDF shows the deduction lines an accountant knows.
* Bad: the rate of a deduction line is that of the final invoice's tax point; a rate change between advance and supply needs a corrective step not built yet.
* Bad: corrective tax documents, refunds of an over-covered invoice, the same-period shortcut and ISDOC types 4 and 5 are not included.
