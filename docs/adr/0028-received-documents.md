# 28. Received documents

* Status: accepted
* Date: 2026-11-10

## Context and problem statement

Foresi keeps the sales side only. The cost side, supplier invoices, receipts and received credit notes, lives in a folder of PDFs, so input VAT, self-assessment and the monthly handover to the accountant are done by hand. In the archive we measured (360 documents, February to October 2026) 57 % are in CZK and the rest in USD and EUR, almost half come from suppliers abroad and are self-assessed, a third are Czech tax invoices, and the rest are receipts, zero-amount documents, advance requests and a few credit notes.

The Czech VAT Act decides most of the model. A received tax document has the supplier's own number (§29(1)(e)), which the control statement (KH) reports; the firm's books number it again in their own series. VAT is converted at the rate of the day the tax arises (§4(8)), but the deduction is limited to the CZK VAT a document states (§73(6)). The right to deduct arises on the tax point (§72(5)), can be claimed at the earliest once the firm has the document (§73(2)), and at the latest by the end of the second calendar year after the right arose (§73(3), for rights from 1 January 2025). A simplified document is never allowed where the recipient owes the tax (§30(2)(c)).

## Decision drivers

* One place for every cost document, with the original file beside its values.
* The stored values must be enough for KH sections A.2, B.1, B.2, B.3 and the VAT return rows, without asking again.
* Foreign currencies from the start; the exchange rate is a value the document keeps, wherever it came from.
* Built for the coming API from day one: rules in the domain, authorization in the use cases, reads through query services.

## Considered options

* Record: one record with a kind (chosen); a record type per kind
* VAT detail: a recap per rate (chosen); invoice lines
* Recap storage: one JSON value on the document (chosen); a row per rate in its own table
* Numbering: an evidence series of the module (chosen); the sales series of Invoicing

## Decision outcome

### The module

A new module, `Purchasing`, with Domain, Application and Infrastructure. It builds on Party (the firm and the supplier), Identity (people), the Attachment store (the original file), Tenancy and Audit. It never uses Invoicing: the sales series, `VatRateKind` and `TaxTreatment` live there, so Purchasing has its own small equivalents. Nothing depends on Purchasing except the navigation of Ui. Exchange rates come from the separate `ExchangeRate` module, which the use cases will read; the domain only keeps the rate it is given.

### One record with kinds

`ReceivedDocument` is tenant-owned, audited, its own audit root and belongs to one firm. Its `ReceivedDocumentKind` is a tax invoice, a simplified receipt, a foreign invoice, a credit note, an advance request or another document. Every rule that differs by kind asks the kind. Advance requests and other documents carry no VAT, so their treatment is always "not a VAT document" and they need no VAT recap. A credit note names the received document it corrects by id (`originalDocumentId`), and only a credit note does.

The supplier is a contact (`supplier_id`), with a frozen `SupplierSnapshot` of the name, registration number, VAT ID and address as the document states them. Beside it: the supplier's own number, the variable symbol, the issue date, the tax point, the due date, the received date, the currency, the total, the exchange rate and the day it was published for.

### Our evidence number apart from the supplier's

The supplier's number is a field of the document, the number KH reports. The firm's own evidence number comes from the firm's `EvidenceSeries` (one per firm, format `R{YYYY}{NNNN}` by default; text, `{YYYY}` or `{YY}` and one `{N…}`). It counts per year of the received date in `EvidenceCounter`, written only by one atomic upsert as the sales counter of ADR 0013 is, so a number is never given twice and a rolled-back transaction gives it back. A document takes its number when it is first recorded and keeps it for good, in the trash too; a draft has none, so a draft thrown away leaves no gap. The number is unique per firm.

### Tax treatments

`PurchaseTaxTreatment` says where a document goes in the VAT reports:

| Treatment | Control statement | VAT return |
|---|---|---|
| Domestic | B.2 when the whole document is above 10,000 CZK with VAT, else in the B.3 sum | deduction rows 40 / 41 |
| Domestic reverse charge | B.1, with the tax point | output rows 10 / 11, deduction rows 43 / 44 |
| Service from the EU | A.2 with the supplier's VAT ID | output rows 5 / 6, deduction rows 43 / 44 |
| Service from outside the EU | A.2 with an empty VAT ID | output rows 12 / 13, deduction rows 43 / 44 |
| Not a VAT document | nowhere | nowhere |
| Non-deductible | nowhere, as no deduction is claimed | nowhere |

A simplified receipt is a domestic document: it is refused in the three self-assessed treatments. A credit note takes the treatment of its original and reports negative amounts. KH B.1 also needs the code of the supply; it is not stored yet and comes with the control statement export.

### The VAT recap and the VAT in CZK

The recap holds one row per rate: the rate in percent, the base and the VAT in the document's currency, and the CZK VAT when a document in another currency prints it. Lines are not stored. The rate is kept as the document prints it (21, 12, 0), not as a kind, since a supplier abroad or an old document may use any rate. Each row also holds its base and VAT in CZK:

* The base in CZK is the base times the exchange rate, rounded half up to the minor unit.
* Self-assessed treatments: the VAT in CZK is the base in CZK times the rate, as the firm computes the tax itself. The document states none.
* Domestic and non-deductible: the CZK VAT the document states (§73(6)), otherwise the VAT converted at the exchange rate of the tax point. A CZK document's VAT is in CZK already, so a stated amount is refused there.
* Not a VAT document: zero, and rows with VAT or a rate other than 0 are refused.

Both CZK amounts stay empty while a document in another currency has no rate yet. The recap is one JSON value on the document, checked and audited as a whole: a recap has a handful of rows, and the audit log rejects changes to to-many collections of audited entities. The sums of the base and the VAT, in the currency and in CZK, are columns beside it for lists and reports.

### Deduction period and the latest deduction date

Both are computed on every save, for a firm that files monthly. Domestic VAT is deducted at the earliest in the month of the later of the tax point and the received date, and at the latest on 31 December of the second year after the tax point's year. Self-assessed VAT is deducted in the month of the tax point, in the same return that declares it; it gets no latest date, as the deadline does not bind a deduction of tax the firm declares itself. Non-deductible VAT and documents without VAT have neither. A tax point the document does not name is its issue date, set so on recording. The deduction period is indexed per firm, for the month view.

### The original file

The uploaded file is an attachment of the owner kind `received_document` in the Attachment store (SHA-256, private storage, per-owner access checks), named on the document by `originalAttachmentId`. A draft may take another file; a recorded document keeps the one it was recorded from.

### Draft, recorded and the trash

A draft holds what is known, and only values that do not fit together are refused (a CZK document at a rate other than 1, a simplified receipt in reverse charge, an original on anything but a credit note). Recording needs the evidence number, the supplier, the issue and received dates, the total, an exchange rate for another currency, the original of a credit note and at least one recap row unless the kind needs none. A recorded document never goes back to a draft; editing it checks the same. The trash works as for invoices (ADR 0013): a reason, who and when, no change until it is restored, and the number kept. Every refusal is a `ReceivedDocumentRefused` with a stable reason code, which the screens translate and the API returns.

### API-ready

The use cases check who may do what themselves; no controller of the module carries `StaffCheck` or `OwnerCheck`. Lists and details come from query services in `Application/Query`, so the screens, the API and MCP read the same. Controllers only parse forms and render. The repository ports (`ReceivedDocuments`, `EvidenceSeriesCatalog`, `EvidenceNumbers`) are in Application, their Doctrine implementations in Infrastructure.

### Consequences

* Good, because one record and one list hold every cost document, and the kind alone decides what it needs.
* Good, because the stored fields already cover the control statement and the VAT return; their export is a report over them.
* Good, because the VAT in CZK is computed once, when the document is saved, and stays what it was when the rate source changes later.
* Bad, because the recap per rate cannot tell the lines of a long usage invoice apart; lines can come later as a separate table.
* Bad, because a sum by rate over many documents reads JSON in PHP rather than in SQL; for the volume of one firm's month that is small.
* Bad, because the deduction dates assume monthly filing; a quarterly payer needs the period of its quarter.
* Room for paying: the due date, the variable symbol and the total are there for pairing outgoing payments.
* Room for a proportional or reduced deduction (§75, §76) as a deduction mode beside the treatment.
