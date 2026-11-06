# 24. The move from iDoklad

* Status: accepted
* Date: 2026-11-06

## Context and problem statement

Most firms that would start with Foresi invoice in iDoklad today, many of them for years. To switch they need their contacts, number series, issued documents with payments and tax documents, and recurring invoices in Foresi, with numbering that goes on where iDoklad stopped and sums that agree with iDoklad's. The file import of contacts ([ADR 0008](0008-asynchronous-import-pipeline.md)) covers contacts only, and iDoklad's exports do not carry payments, tax documents, deductions or the schedule of recurring invoices. A firm also needs to see what would happen before anything is stored, to take a bad import back, and to switch on a day of its choosing without two systems issuing the same recurring invoice.

## Decision drivers

* The firm's iDoklad account is never changed by the move, by mistake or otherwise.
* Every record that is not moved is named with its reason; nothing is guessed.
* What Foresi stores is what Foresi would compute and print, and any difference from iDoklad is visible before the import.
* The iDoklad API quota of a tariff (7 500 requests a month on Oblíbený) is not spent twice for the same data.
* The invoicing module stays free of iDoklad's concepts, so other sources can follow.
* An import can be taken back while nothing in Foresi stands on it.

## Considered options

* Source: the iDoklad API v3 with an allow-list of GET paths (chosen); the CSV and XLSX exports through the file import; the ISDOC files of the documents
* Flow: copy the account into a stored snapshot, then import from it in stages (chosen); import while reading the API
* Totals: price each document again and compare with iDoklad's totals (chosen); store iDoklad's totals as they are
* PDFs: keep iDoklad's PDF as the document's original (chosen); draw every imported document with Foresi's layout only
* Identity of imported records: a links table in the iDoklad module (chosen), with an import source and external id only on documents and payments; an external id column on every target table
* The check: the same run in a transaction that is rolled back (chosen); a separate dry-run path per stage
* Undo: for 30 days, all or nothing, refused while something done here stands on an imported record (chosen); no undo; undo of single records
* Recurring invoices: imported paused (chosen); imported active

## Decision outcome

**The API with a GET allow-list.** Only the API gives payments, tax documents on payment, deductions and the schedule of recurring invoices with what was issued. Every path the importer may read is listed in `ReadOnlyPaths`; the HTTP session sends only GET requests and refuses any other path before a request leaves. The client credentials are sealed with `MAIL_SECRET_KEYS`, like mail server passwords. Requests are counted per pull, so the screen shows the month's use against the tariff's quota; a 429 waits as iDoklad asks.

**A snapshot, then stages.** A pull copies each list page by page into `idoklad_record` rows, stored as they arrive, and then the PDFs in batches; a pull that stops keeps what it got. A delta pull asks only for records changed after the latest change of the last pull that succeeded, so a second pull before the switch costs little of the quota. The import reads only the snapshot, in ordered stages (contacts, series, documents, payments with tax documents and deductions, recurring templates), each one a tagged service with its order, so a check and an import never spend a request and can be repeated freely.

**Recompute and compare, never store totals.** Each document goes through Invoicing's own pricing with the Czech VAT rates valid on its day, back to 2013, and the computed totals are compared with iDoklad's. A document that differs is not imported and is reported with both sets of totals. The report sums documents by year on both sides, so a firm reconciles the move before and after the run; after a run the Foresi side is read from what was stored. Storing iDoklad's totals would let a document disagree with its own lines, its PDF and its ISDOC file ([ADR 0014](0014-invoice-amounts-rounding-and-concurrent-edits.md)).

**iDoklad's PDF as the original.** An imported document keeps the PDF iDoklad drew and sent as its original, beside the database, and Foresi serves that original first; the customer already has that document, and it is the one an auditor would see. A document drawn by Foresi would differ in layout from what was issued. A check never reads the files.

**Links in the module.** `idoklad_link` records what each iDoklad record became (firm, resource, iDoklad id, target id, run). Only the iDoklad module knows iDoklad ids, and a second run finds the link and leaves the record alone. Documents and payments carry an import source, external id and run id as well, because Invoicing needs them to recognise its imported documents, to price and number them as imported, and to undo a run without knowing iDoklad. Contacts and series carry nothing: once matched or created they are the firm's own.

**The check is a rolled-back run.** A check runs every stage exactly as an import does, in one transaction, and rolls it back; only the report is stored. There is no second code path that could drift from the real one, and a check sees the same refusals, matches and totals as the import would. A run uses the same transaction and commits it, so a run that stops stores nothing but its report.

**Undo for 30 days, with blockers.** An undo deletes the run's documents and payments, its templates nobody touched and their links, in one transaction, all or none. It is refused with a reason per record when anything done here since stands on an imported record: a change, an export for the accountant, a send, a payment, a correction, a tax document or a deduction made in Foresi, or a cancellation. Imported documents were not issued here, so deleting them gives up no number of the firm's own numbering; the audit log keeps every deleted row. Contacts and series stay, as other records may use them by then.

**Templates paused.** A recurring invoice becomes a paused template that carries over its issued count and last issue, and whose next date must equal iDoklad's next issue or it is refused. The firm resumes each template after its iDoklad twin ran for the last time, so no customer gets an invoice from both systems or from neither. See [recurring.md](../recurring.md#cutover).

### Consequences

* Good: the account is copied once and imported as often as needed; a check costs no quota and stores nothing but its report.
* Good: every record that does not come over is named with a reason, and a total that differs is visible before the import, with the sums by year to reconcile.
* Good: imported documents behave as Foresi's own (reminders, payments, deductions, the accountant pack) and still show the PDF the customer received.
* Good: Invoicing's import paths and the links table carry no iDoklad concepts, so another source can be added as another module.
* Bad: documents in another currency, credit notes and recurring advance invoices are not moved yet; the firm keeps them in iDoklad or enters them by hand.
* Bad: a document whose totals differ by rounding in iDoklad is refused rather than stored as printed, and must be fixed in iDoklad or entered by hand.
* Bad: the PDFs take one request each, so a large account may need more than one month's quota to download whole.
* Bad: an undo is all or nothing; after 30 days, or once one imported record is used, the run stays.
