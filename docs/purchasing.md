# Received documents

The cost side of the books: supplier invoices, receipts and received credit notes, kept in one list beside the original file. This page says what the screens do and what is still to come; the decisions behind the model are in [ADR 0028](adr/0028-received-documents.md). It is a statement of the software, not tax or legal advice: every rule here is subject to professional tax and legal review, and you are responsible for the documents you book.

The list is **Money, Costs** in the sidebar (in Czech, **Finance, Náklady**), under the bank. The number beside it counts the drafts that wait to be completed.

## Who may use it

Owners and team members of the organisation. A guest never sees received documents: the menu item is not shown and every address of the module answers "forbidden". The same check sits in every use case of the module, not in the screens, so the coming API and MCP are refused alike. See [people](people.md) for the roles.

## Concepts

- **Received document.** One record for everything a firm receives and books as a cost. It belongs to one of your firms and keeps the original file, the supplier, the dates, the amounts and a VAT recap.
- **Kind.** A tax invoice, a simplified receipt (a simplified tax document), a foreign invoice, a credit note (a corrective tax document), an advance request or another document. The kind decides what a document needs: advance requests and other documents carry no VAT, so they are always "not a VAT document" and need no VAT recap; a credit note names the recorded document it corrects, and only a credit note does.
- **Supplier.** A contact of the address book. The document keeps a frozen copy of the supplier's name, registration number, VAT ID and address as the document states them, so a later edit of the contact never changes a recorded document.
- **Supplier's number and our evidence number.** The supplier's number is the number printed on the document, the one the control statement reports. The evidence number is the firm's own number in its books (see [Evidence number](#evidence-number)).
- **VAT recap.** One row per VAT rate: the rate as the document prints it (21, 12, 0 or any other, since a supplier abroad may use any rate), the base and the VAT in the currency of the document, and for a document in another currency the VAT in CZK it states. Invoice lines are not stored; a recap is enough for the control statement and the VAT return.

## Drafts and recorded documents

A document is first a **draft** or is **recorded**:

- **Upload.** **Upload documents** in the page header opens a dialog. Drop or choose PDFs or images (a PDF is previewed beside the form, an image too); each file is sent on its own with its progress, and each becomes a draft on the tab **To complete**. A file the server refuses says why next to its name. Without JavaScript one form sends every file chosen.
- **New document.** **New document** in the header opens a dialog with the same fields as the page of a document, for a document typed in without a file. It is recorded at once and the dialog then leads to it.
- **Draft.** A draft holds what is known and refuses only values that do not fit together (a CZK document at a rate other than 1, a simplified receipt in a reverse charge treatment, an original on anything but a credit note). It has no evidence number, so a draft thrown away leaves no gap in the numbers. **Delete draft** asks first and removes the draft and its file; the history keeps a note.
- **Record.** **Record** in the page header needs the supplier, the issue date, the received date, the total, an exchange rate when the currency is not CZK, the original of a credit note and at least one recap row, unless the kind needs none. Recording gives the document its evidence number. A recorded document never goes back to a draft; editing it checks the same rules, and a recorded document keeps the file it was recorded from.
- **Changing the kind, the treatment or the currency** sends the form back for what the new value needs (the exchange rate, the supply code, the original of a credit note, the CZK VAT a document in another currency states). It saves nothing. A refusal is shown next to its field and in a summary over the form.
- **Two people editing the same document** cannot overwrite each other: a save from a form opened before another save is refused with a message and a link to load the document again.

The list has the tabs **To complete**, **Recorded** and **Trash**, each with its count, a filter by firm, month, supplier, kind and VAT treatment, and a search over the supplier and the supplier's number.

### Evidence number

Each firm has an evidence series, by default `R{YYYY}{NNNN}` (`R20260001`). The counter runs per year of the received date and is written by one atomic step, as the sales counter is (see [ADR 0013](adr/0013-invoice-numbers-and-trash.md)), so a number is never given twice and a rolled-back save gives it back. A document takes its number when it is first recorded and keeps it for good, in the trash too. The number is unique per firm. This release has no screen to change the series; the default applies to every firm.

## VAT treatments

The treatment says where a document goes in the VAT reports.

| Treatment | Control statement | VAT return |
| --- | --- | --- |
| Domestic | B.2 when the whole document is above 10,000 CZK with VAT, else in the B.3 sum | deduction rows 40 / 41 |
| Domestic reverse charge | B.1, with the tax point | output rows 10 / 11, deduction rows 43 / 44 |
| Service from the EU | A.2 with the supplier's VAT ID | output rows 5 / 6, deduction rows 43 / 44 |
| Service from outside the EU | A.2 with an empty VAT ID | output rows 12 / 13, deduction rows 43 / 44 |
| Not a VAT document | nowhere | nowhere |
| Non-deductible | nowhere, as no deduction is claimed | nowhere |

- **Self-assessment.** In the three self-assessed treatments (domestic reverse charge, service from the EU, service from outside the EU) the supplier charges no Czech VAT. The firm computes the tax itself, so the VAT in CZK is the base in CZK times the rate, and the document states none. The firm declares it as output VAT and deducts it in the same return.
- **Domestic and non-deductible.** The VAT in CZK is the amount the document states in CZK (the deduction is limited to it), or else the VAT converted at the rate of the tax point. A document in CZK has its VAT in CZK already, so a stated amount is refused there.
- **Not a VAT document.** The amounts in CZK are zero, and rows with VAT or a rate other than 0 are refused.
- **Simplified receipts** are domestic documents: they are refused in the three self-assessed treatments, since a simplified document is never allowed where the recipient owes the tax.
- **Credit notes** take the treatment of the document they correct and report negative amounts.
- **Supply code.** The control statement's section B.1 also needs the code of the supply. A domestic reverse charge may carry it, optional as on issued documents; no other treatment takes one.

The stored values are what the control statement and the VAT return read. Their export is not part of this release; see [What comes later](#what-comes-later).

## Foreign currency

A document in a currency other than CZK is converted at an exchange rate, the CZK price of one unit of the currency, kept with the document as a decimal and never as a float.

- **The ČNB rate.** When the rate field is left empty, the document takes the Czech National Bank rate of its tax point, or of its issue date when it names no tax point. The bank publishes on business days around 14:30 Prague time; a weekend, a holiday or the hours before the publication get the previous publication, and the document says which day the rate was published for. The bank quotes some currencies per 100 or 1000 units; the document keeps the rate of one unit. The rates come from the daily fetch described in [operations](operations.md#exchange-rates).
- **A rate you typed wins.** On the form the rate field shows the published rate when one is known, with the day it was published. A rate left as shown is kept as the published one. A rate you change, or type into an empty field, is kept exactly as typed and no publication day is stored for it. If you change the tax point or the currency and leave the rate as it was shown, the published rate of the new day takes its place. Nothing replaces a typed rate later, and the stored rate does not change when the bank's rates are fetched again.
- **No rate yet.** A draft whose rate is not published yet stays without one and without CZK amounts. Recording is refused with "type it by hand" when there is still none.
- **Rounding.** The base in CZK is the base times the exchange rate, rounded half up to the minor unit. The VAT in CZK is computed once, when the document is saved, and stays what it was.

The detail page shows the conversion under the form: base, VAT and total in CZK, and a row per rate.

## Deduction dates

For a firm that files VAT monthly, the document computes the first month its VAT can be deducted in and the last day to claim it, on every save:

- **Domestic VAT** is deducted at the earliest in the month of the later of the tax point and the received date, and at the latest on 31 December of the second year after the year of the tax point (the right is lost after that).
- **Self-assessed VAT** is deducted in the month of the tax point, in the same return that declares it. It has no latest date, as the deadline does not bind a deduction of tax the firm declares itself.
- **Non-deductible VAT and documents without VAT** have neither.
- **The tax point** a document does not name is its issue date; recording sets it.

A quarterly VAT payer has the deduction period of its quarter; this release assumes monthly filing.

## Warnings

A save that went through tells its warnings in a notice. A warning never stops the save; it asks a person to look.

- **Same file.** Another document of the firm has the same original file (compared by its SHA-256 hash, the trash excluded).
- **Same supplier's number.** The supplier's number is already on another recorded document of the same supplier (compared without regard to case).
- **Deduction deadline passed.** The latest deduction date is behind us.
- **Deduction deadline near.** The latest deduction date is at most 60 days away.
- **Simplified receipt over the limit.** A simplified receipt above 10,000 CZK with VAT (at the document's rate for another currency).

## Trash and restore

**Move to trash** in the page header of a recorded or draft document asks for a reason in a dialog. The document keeps its number, its values and its file; the trash records who, when and why. Nothing in a trashed document can be changed until it is restored, and the **Trash** tab lists it with its reason. **Restore from the trash** brings it back as it was, with the same evidence number.

The trash and the history work as for invoices (see [ADR 0013](adr/0013-invoice-numbers-and-trash.md)). Every change reaches the audit log, and each document shows its history: created, recorded, changed, trashed and restored, with who and when.

## Operating it

Received documents are rows of the application database, tenant-isolated like the rest. The original files are attachments in the `attachments` directory of the share directory, outside the web root and the database; the ops backup already includes the whole share directory (see [operations](operations.md)). Run `bin/console doctrine:migrations:migrate` on upgrade. The ČNB rates are fetched by a scheduled job of the worker; see [operations](operations.md#exchange-rates).

## What comes later

Not in this release:

- **Export** of the control statement and the VAT return from the stored values, and a month close that locks the documents of a closed month.
- **Own evidence series** per firm, with another format, in the settings of the firm.
- **Quarterly VAT payers**: deduction periods by quarter.
- **Invoice lines** on a received document, for the lines of a long usage invoice; the recap per rate is all that is stored.
- **A proportional or reduced deduction** (sections 75 and 76 of the VAT Act) beside the treatment.
- **Paying**: pairing outgoing bank payments with the due date, variable symbol and total already kept.
