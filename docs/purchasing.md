# Received documents

The cost side of the books: supplier invoices, receipts and received credit notes, kept in one list beside the original file. This page says what the screens do and what is still to come; the decisions behind the model are in [ADR 0028](adr/0028-received-documents.md). It is a statement of the software, not tax or legal advice: every rule here is subject to professional tax and legal review, and you are responsible for the documents you book.

The list is **Money, Costs** in the sidebar (in Czech, **Finance, Náklady**), under the bank. The number beside it counts the drafts that wait to be completed.

## Who may use it

Owners and team members of the organisation. A guest never sees received documents: the menu item is not shown and every address of the module answers "forbidden". The same check sits in every use case of the module, not in the screens, so the [REST API](api.md#received-documents) and [MCP](mcp.md) are refused alike. See [people](people.md) for the roles.

## Concepts

- **Received document.** One record for everything a firm receives and books as a cost. It belongs to the firm and keeps the original file, the supplier, the dates, the amounts and a VAT recap.
- **Kind.** A tax invoice, a simplified receipt (a simplified tax document), a foreign invoice, a credit note (a corrective tax document), an advance request or another document. The kind decides what a document needs: advance requests and other documents carry no VAT, so they are always "not a VAT document" and need no VAT recap; a credit note names the recorded document it corrects, and only a credit note does.
- **Supplier.** A contact of the address book. The document keeps a frozen copy of the supplier's name, registration number, VAT ID and address as the document states them, so a later edit of the contact never changes a recorded document.
- **Supplier's number and our evidence number.** The supplier's number is the number printed on the document, the one the control statement reports. The evidence number is the firm's own number in its books (see [Evidence number](#evidence-number)).
- **VAT recap.** One row per VAT rate: the rate as the document prints it (21, 12, 0 or any other, since a supplier abroad may use any rate), the base and the VAT in the currency of the document, and for a document in another currency the VAT in CZK it states. The recap is the only source of the VAT: it is enough for the control statement and the VAT return.
- **Lines.** Optional: what the document was for, split by the rates of its recap (see [Lines](#lines)).

## Drafts and recorded documents

A document is first a **draft** or is **recorded**:

- **Upload.** **Upload documents** in the page header opens a dialog. Drop or choose PDFs or images (a PDF is previewed beside the form, an image too); each file is sent on its own with its progress, and each becomes a draft on the tab **To complete**. A file the server refuses says why next to its name. Without JavaScript one form sends every file chosen.
- **New document.** **New document** in the header opens a dialog with the same fields as the page of a document, for a document typed in without a file. It is recorded at once and the dialog then leads to it.
- **Draft.** A draft holds what is known and refuses only values that do not fit together (a CZK document at a rate other than 1, a simplified receipt in a reverse charge treatment, an original on anything but a credit note). It has no evidence number, so a draft thrown away leaves no gap in the numbers. **Delete draft** asks first and removes the draft and its file; the history keeps a note.
- **Record.** **Record** in the page header needs the supplier, the issue date, the received date, the total, an exchange rate when the currency is not CZK, the original of a credit note and at least one recap row, unless the kind needs none. Recording gives the document its evidence number. A recorded document never goes back to a draft; editing it checks the same rules, and a recorded document keeps the file it was recorded from.
- **Changing the kind, the treatment or the currency** sends the form back for what the new value needs (the exchange rate, the supply code, the original of a credit note, the CZK VAT a document in another currency states). It saves nothing. A refusal is shown next to its field and in a summary over the form.
- **Two people editing the same document** cannot overwrite each other: a save from a form opened before another save is refused with a message and a link to load the document again.

The list has the tabs **To complete**, **To review**, **Recorded** and **Trash**, each with its count, a filter by month, supplier, kind, VAT treatment, reading and source, and a search over the supplier and the supplier's number. The **Source** column says how a document came in (Upload, Manual, API, E-mail), and a draft made from a file shows where its reading stands as a second badge beside its state.

## Reading the file of a draft

Each uploaded file is read in the background, and what it finds fills the draft while nobody has changed it.

- **The panel over the fields** says where the reading stands: *Reading the file…* (the panel is loaded again every 2 seconds until it finishes, then offers to show the values), *Read from ISDOC, QR payment code* with the sources that found values, *Nothing found in the file*, or *Reading failed*. Its warnings (some values did not fit, no contact or more than one has the supplier's numbers) are listed there.
- **A mark at each field** ("From ISDOC", "From QR") says where its value came from while the field still holds the value read; a value a person changed loses its mark.
- **Found in the file.** Values the reading left as suggestions (the draft was saved by a person first, or the values did not fit together) and that the draft does not hold are listed with a checkbox each; **Use selected** saves the checked ones into the draft as a save from the form would, with the same version check and refusals.
- **Create supplier.** When no contact has the supplier's numbers, the button beside the supplier opens the new contact dialog filled with the name, registration number and VAT ID read; its registry lookup completes the rest.
- **Read again** in the menu of the page header reads the file of a draft once more, with the readers that run only on request. It is refused with `reading_in_progress` while a reading asked for less than 10 minutes ago runs (an older one counts as stuck), with `no_original_file` for a draft without a file, and for a recorded document or one in the trash.
- **Read with AI.** When the optional [AI Reader](ai-reader.md) module has a provider and a key and its trigger is `click`, the menu of the page header also offers **Read with AI**, which asks for the same reading as Read again. With the trigger `arrival` the AI reader runs by itself, but only for a file the structured readers left without a total, an issue date or a supplier's registration number or VAT ID; Read again always runs it. What it sends to the provider is described in its own page. Purchasing does not name the provider and works the same without the module.
- **To review** lists the drafts whose file was read, whatever it found, until they are recorded.
- **By mail.** Each person has a capture address, which the Mail module keeps (purpose `received_documents`) and the person manages in [Settings → Received documents](#settings) or over Mail's REST and MCP calls. A mail sent or forwarded to it is taken in only while the person still works for the organisation; Mail keeps nothing of a mail to an unknown or revoked address. A background job then files the attachments as drafts of that person, in mail order, with `source` `mail`, and each draft is read like a file dropped on the screen. An attachment becomes a draft when its bytes are a PDF, an image (PNG, JPEG, GIF, WebP, HEIC), an ISDOC file (`.isdoc`, `.isdocx`) or XML, as for the API upload, and it is at most 10 MB. An image that is inline and has a Content-ID is a signature or a logo and is left out; an inline PDF is kept. Other parts are left out as `unsupported_type`, `too_large` or, beyond 50 drafts for one mail, `too_many`. A file already on another document is no reason to refuse: the draft warns `duplicate_file`, and `duplicate_supplier_number` once the reading knows the supplier. When Mail's check of the sender failed, every draft of the mail warns `sender_unverified`; an unknown result adds nothing. A document filed from a mail shows a line *From e-mail* on its page with the sender, the subject and the date sent, as Mail stores them; they are the sender's own text, shown as text, and the capture address is cut out of both. Each mail has a row in the intake log with the outcome (`filed`, `nothing_accepted` or `failed`), the number of drafts and the names of the attachments left out with their reason. Once a mail is filed, or nothing in it was accepted, Purchasing releases it to Mail's purge 30 days on, so its sender and subject are still shown for a month; a mail whose filing failed is kept, as it may be the only copy of a document.
- **Over the API and MCP** the detail of a document has `source`, `source_mail` (for a document filed from a mail `{from, subject, sent_at, verdict}`, the sender and the subject with the capture address cut out and Mail's check of the sender: `verified`, `failed` or `unknown`; otherwise `null`) and `reading`: the state, the sources, each value found with its source and whether the draft took it (`applied`) or left it as a suggestion, the warnings, and `failure` as a code, never a reader's or provider's text. A matched supplier is the field `supplier_name`. `POST /api/v1/received-documents/{documentId}/read-again` (`foresi_read_received_document_again`) asks for the reading again and answers `202` at once; it is refused with the same reasons as the screen, each as `409`. The list takes `reading` and `source`, and the view `purchasing.received.to_review`. `POST /api/v1/received-documents/upload` (`foresi_upload_received_document`) makes a draft from one file sent as base64, up to 10 MB: its `source` is `api`, it is read like a file dropped on the screen, and a file Foresi refuses makes no draft. See [REST](api.md#received-documents) and [MCP](mcp.md).

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

## The Plan panel

With Cash flow installed, the page of a recorded received document shows a **Plan** panel between its figures and its history, and recording a document lets Cash flow pair it with the occurrence of a planned item of its supplier (see [Pairing documents with planned occurrences](cash-flow.md#pairing-documents-with-planned-occurrences)). Credit notes pair with planned income. Owners and members see it.

- **Paired automatically**: "Paired with *item* · 11/2026 (automatically)" and **Unpair**.
- **Confirmed**: the same without "(automatically)", and **Unpair**.
- **Proposed**: the items and months it may belong to, each with **Confirm**, and in the panel header **Another item…** (a dialog that picks an item and the day of its occurrence among those two months either side of the document) and **Do not pair**.
- **Not paired**: "Not paired" with **Pair…**, or, when Cash flow found nothing, "No planned item matches yet" with **Pair…**.

**Unpair** and **Do not pair** say the document belongs to no occurrence, and Cash flow never pairs it again on its own; **Pair…** and **Confirm** bring it back. A refusal, such as a day the item does not fall on, is told above the page.

The list of received documents offers the condition **Pairing to confirm** in the filter sheet (the Filter icon): the documents Cash flow proposes occurrences for and waits for a person to decide. Like any condition it can be part of a saved view.

## Trash and restore

**Move to trash** in the page header of a recorded or draft document asks for a reason in a dialog. The document keeps its number, its values and its file; the trash records who, when and why. Nothing in a trashed document can be changed until it is restored, and the **Trash** tab lists it with its reason. **Restore from the trash** brings it back as it was, with the same evidence number.

The trash and the history work as for invoices (see [ADR 0013](adr/0013-invoice-numbers-and-trash.md)). Every change reaches the audit log, and each document shows its history: created, recorded, changed, trashed and restored, with who and when.

## Lines

A document may say what it was for in lines: each has a text (at most 255 characters), a base without VAT in the document's currency and a rate of its VAT recap. Per rate, the bases of the lines add up to the base of that rate in the recap; a document without a recap (not a VAT document, an advance request) has the one rate 0, and its lines add up to its total. At most 100 lines. A stored line has an id, which a read of the document gives as the `key` of the line and which stays the same when the line is edited, moved or an earlier line is removed: send it back with a line to keep that line, send a line without an id to add one, and a stored line left out of the list is removed. An id the document does not hold counts as a new line, and the same id twice in one list is refused with `duplicate_line_id`. Reordering the lines keeps their ids. Other modules that point at a line by its key (such as a margin allocation) rely on this. The lines never change the recap, the VAT, the CZK amounts or the total: the recap stays the only source of the VAT. The base in CZK of a line is its base at the document's rate, and the last line of each rate takes the rounding difference to the recap's base in CZK.

A document without lines reads as one implicit line per rate of its recap, named by the kind of the document, so a report always has lines to read. A draft and a recorded document take lines, a document in the trash or of a closed month does not. A save that changes the recap of a document with lines must send lines that fit the new recap, or it is refused with `lines_do_not_add_up`. The lines are set over the [REST API](api.md#received-documents) and [MCP](mcp.md), or given when a document is recorded there.

On the screen, the lines sit under the VAT recap of the form, of a document and of a new one in the dialog. **Add line** adds a row of a text, a base and a rate, which offers the rates of the recap (only 0 for a document without one); each row has its own remove button, and a row of a saved line carries that line's id, so removing a row or editing the others keeps the ids of the rest. Adding and removing a row sends the form back without saving, as the rows of the recap do. Under the rows the form says what is left to break down at each rate, such as "120.00 Kč left to break down at 21 %", in the document's currency; a save is refused while anything is left, and the refusal is told at the lines and in the summary over the form. A row left empty is no line, and no lines at all is valid: the whole document is then one line. The lines are always sent with the recap, so a new recap and the lines that fit it are saved together. Under the amounts in CZK, the page lists the lines as saved, with the base in CZK for a document in another currency; a document without lines of its own says "The whole document as one line" and lists its implicit line per rate, named by its kind.

## Margin

With the Margin module installed, a recorded document shows a **Margin** panel: who bears the cost of its lines, the firm itself or a client, and the shares in CZK. Purchasing is the cost source of Margin: it gives its recorded documents, their lines with the base in CZK and the month of the document, and tells Margin when a document is recorded, edited, trashed or restored, so the shares follow. See [margin.md](margin.md). Without the module nothing of this is shown.

## Settings

**Settings → Received documents** (`/settings/received-documents`, for the owners and members) holds the signed-in person's capture address:

- **Create address** in the page header opens a dialog for its label. One address per person; a second is refused.
- With an address, the page shows it in full with a **Copy** button, its label, when the last e-mail came and whether the daily limit turned e-mails away today. The menu of the page header has **Rename address** (a dialog), **Replace address** and **Revoke address**; the last two ask first. Replace stops the old address at once and makes a new one with the same label; mail sent to the old one is not kept. The page is never cached, and the address is never shown to anyone else.
- When the server cannot make addresses (no `MAIL_INBOUND_ADDRESS`, or no `MAIL_SECRET_KEYS`), the page says so and links to [mail.md](mail.md).
- **Forward supplier mail** has short steps for Gmail, Outlook and Apple Mail: forward a mail by hand, or with a rule that forwards the supplier's mail to the address. Gmail forwards by itself only to an address it confirmed with a code it mails there, and Foresi does not show the text of that mail, so with Gmail forward by hand.
- **Recent e-mails** lists the person's last 20 mails: when it came, the sender and the subject (the sender's text, with the address cut out, shown as text; "No longer kept" once Mail purged the mail), the outcome (the number of drafts, "No attachment we can read", "Failed", or "Waiting" while the job has not run) and the attachments left out with their reason. A mail that made drafts opens its first draft.

Below these, the page shows the sections other modules add through the slot `purchasing.settings_sections`. With the AI Reader module installed it shows whether reading by AI is set up, with which provider, model and trigger, and that a document read by AI is sent to the provider; it never shows the key. A module also adds rows to the menu of a draft's page through `purchasing.document_actions`. Both slots are described in `Foresi\Purchasing\Contract\PurchasingSlots`.

## Payments

A recorded document keeps its payments, built the way the payments of issued invoices are ([ADR 0019](adr/0019-payments-and-allocations.md)): the day, the amount in the document's currency, the method (`bank_transfer`, `card`, `cash` or `other`) and, when known, the bank movement that paid it. A document may have several; together they are never more than its total. What is paid and what remains are derived from them, never stored. For a credit note the payments are what the supplier returned, and its negative total remains until they reach it.

**Mark as paid** records one payment of all that remains, dated today in the organisation's time zone, so a document marked as paid at 00:30 in Prague is paid that day, not the day before in UTC. A draft, a document in the trash and one paid in full take no payment. A closed month does not stop one, as a payment changes nothing the VAT reports read. A payment recorded by mistake is removed, and the audit log keeps it.

Payments are recorded over the [REST API](api.md#received-documents) and [MCP](mcp.md) for now; the screens show them in a later step. Recording a document for the first time records the event `purchasing.received_document.recorded`, and the payment that leaves nothing to pay `purchasing.received_document.paid`, for the event feed and webhooks.

A recorded document records more events, each with the document as its subject and no amounts (ADR 0030):

| Event | When | Payload |
| --- | --- | --- |
| `purchasing.received_document.changed` | It was saved again (`what` is `edited`; it may change anything, the lines included), or its lines were set to other lines alone (`what` is `lines`). | `received_document_id`, `what` |
| `purchasing.received_document.trashed` | It was moved to the trash. | `received_document_id` |
| `purchasing.received_document.restored` | It came back from the trash. | `received_document_id` |

A draft records none of them; its first recording records `recorded`, never `changed`. One save records one event.

## Operating it

Received documents are rows of the application database. The original files are attachments in the `attachments` directory of the share directory, outside the web root and the database; the ops backup already includes the whole share directory (see [operations](operations.md)). Run `bin/console doctrine:migrations:migrate` on upgrade. The ČNB rates are fetched by a scheduled job of the worker; see [operations](operations.md#exchange-rates).

## What comes later

Not in this release:

- **Export** of the control statement and the VAT return from the stored values, and a month close that locks the documents of a closed month.
- **Own evidence series** per firm, with another format, in the settings of the firm.
- **Quarterly VAT payers**: deduction periods by quarter.
- **A proportional or reduced deduction** (sections 75 and 76 of the VAT Act) beside the treatment.
- **Pairing outgoing bank payments** with a document by its due date, variable symbol and total, recorded as its payment; and payments on the document's page.
