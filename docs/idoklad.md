# Moving from iDoklad

Foresi can move a firm's iDoklad account over through the iDoklad API: contacts, number series, issued invoices, advance invoices, payments with their tax documents, and recurring invoices. The move reads the account, never writes to it, and every step can be checked before anything is stored. This page is for the owner who moves and for the operator who runs the installation.

The move is a wizard per firm, opened with **Move from iDoklad** on the list of imports (`/import/idoklad`) by the organisation's owners. With one firm it opens that firm; with more it asks which one. Its steps are Connect, Download, Check, Import and Result. **History of the move** lists every download and every check and import with what it did.

## What moves and what does not

| iDoklad | In Foresi |
|---|---|
| Contacts | Contacts with addresses, e-mail, phone, website, bank account, the contact person and the due days. An existing contact is found by its registration number, else by its exact name, and only gets what it lacks; nothing the user typed is overwritten. The reminder setting becomes the firm's own setting of a created contact |
| Number series | Series of the same kind. iDoklad keeps a series per year; the years of one format become one series that counts per year. A matching series is reused. The counter of each year continues after iDoklad's last number |
| Issued invoices and proforma (advance) invoices | Imported invoices and advance invoices, in the order of their numbers, with the purchaser and the firm as printed on them, the VAT regime, the rounding, the sent and exported marks, and the original PDF |
| Payments of issued and proforma invoices | Manual payments on their days, allocated to the document they paid. No bank movement is made and nothing is sent |
| Tax documents on payment | Tax documents linked to their payment and advance, in place of the one Foresi would issue on the payment |
| Invoices that deduct advances | Invoices with Foresi's deduction lines for what the customer may deduct of each advance |
| Recurring invoices | Recurring templates, **paused**, with their schedule and what iDoklad already issued; see [recurring.md](recurring.md#moving-from-idoklad) |

Not moved, and reported with a reason where iDoklad has the record:

- Credit notes (they are listed as refused).
- Documents and payments in another currency than the firm's home currency, and payments of other documents than issued and proforma invoices.
- Received invoices, cash documents, price lists, stock and anything else outside the lists above.
- Recurring advance invoices, templates with the reverse charge, in another currency or language, or with a variable Foresi does not know.
- A record Foresi cannot read safely is refused with its reasons. Foresi never guesses a value.

Documents are priced again by Foresi with the Czech VAT rates valid on their day, back to 2013, and compared with iDoklad's totals. A document whose totals differ is not imported; the report shows it with both amounts (see [The check report](#the-check-report)).

## Before you start: tariff and keys

The iDoklad API needs the **Oblíbený** or **Prémiový** tariff. In iDoklad open **Nastavení › Aplikace › API** and copy the **Client ID** and **Client secret**. In the Connect step paste both; Foresi tries them with one read and says whether iDoklad refused the keys or the tariff has no API access. The secret is stored sealed with the installation's `MAIL_SECRET_KEYS` and is never shown again. **Disconnect** deletes the keys; downloaded data and imported records stay.

## Read-only guarantee

Foresi only reads the iDoklad account. Every request is a GET of a path on a fixed allow-list of read-only API paths; any other method or path is refused before a request is sent. The move never changes a document in iDoklad, never marks anything exported and never sends mail from iDoklad. iDoklad stays usable as before until you switch it off.

## Download and quota

**Download data** copies the account into a snapshot stored by Foresi: each list page by page, stored as it arrives, then the PDFs of the documents in batches of 100, one request each. A download that stops keeps what it got and says why. It runs in the background; the page shows its progress and can be left.

**Download changes** asks only for what changed in iDoklad after the latest change of the last download that succeeded. Lists without a change filter (number series, payments and the code lists) are downloaded whole each time. Use it shortly before the import, so the snapshot is current.

Every request counts against the API quota of the iDoklad tariff, which is per month (7 500 requests on Oblíbený; other tariffs differ). The Download step shows the requests used this month. The API also allows 200 requests a minute; Foresi waits when iDoklad asks it to and tries again. The PDFs take most of the requests, one per document, so a large account may need the next month's quota to finish; the download goes on where it stopped.

## The check report

**Check the import** runs the whole import on the snapshot and rolls it back: nothing is stored except the report. The report shows:

- **Counts** of what would be created, matched (found in Foresi), linked before (by an earlier import), refused and differing.
- **By area**: the same counts for contacts, number series, documents, payments and recurring invoices.
- **Documents by year**: per year of issue, the number and total with VAT in iDoklad, what Foresi computed or stored for the documents it imports, the difference, and how many refused or differing documents explain it. Documents in another currency are only counted. A difference that is not zero is highlighted.
- **Refused records** with the reason for each.
- **Records with different amounts**, with iDoklad's and Foresi's net, VAT, total and amount payable side by side.
- **Next issues of recurring invoices**: the next five dates of each template, to compare with iDoklad's preview.

Fix what you can in iDoklad (or accept the refusal), download the changes and check again. A check older than the latest download says so; check again before importing.

## Reminders start date

Before the import the check asks from which due date Foresi should send payment reminders, and says how many imported invoices are overdue and unpaid. Invoices due before that day were iDoklad's to remind and get no reminder from Foresi. The date is set on the firm's reminder settings when the import ends well. Turning reminders on later moves it to that day again. See [reminders.md](reminders.md).

## The import

**Import** asks for a confirmation with the counts, then runs the same stages as the check and stores the result, in one transaction: an import that stops stores nothing. The result page shows the same report, now against what was stored, so the sums by year reconcile iDoklad with Foresi's own totals. A second import of the same snapshot finds what the first one linked and leaves it alone, so importing after **Download changes** brings only what is new.

## Undo and its limits

The result page offers **Undo import** for 30 days. Undo deletes the import's documents and payments and its recurring templates that nobody touched since, and forgets their links, so a later import brings them again. It is all or nothing, in one transaction. It is refused, with the reason for each record, when something done in Foresi since stands on an imported record:

- the document was changed, exported for the accountant, sent from Foresi, cancelled, or has a payment, a corrective document, a tax document or a deduction made in Foresi;
- a payment was cancelled or taxed by a document issued in Foresi;
- 30 days have passed.

Contacts and number series always stay, as other records may use them by now. A template that was edited, resumed or ended stays too. Deleted documents are not moved to the trash; the audit log keeps every deleted row.

## Cutover

1. Connect, **Download data**, and wait until the PDFs are downloaded.
2. **Check the import**, read the refused and differing records, fix what you want in iDoklad, **Download changes** and check again until the report is what you expect.
3. On the switch day stop issuing in iDoklad, **Download changes**, check once more and **Import** with the reminders start date of that day.
4. Compare the documents by year on the result page with iDoklad's own overview.
5. Recurring invoices arrive paused. Follow the cutover of templates in [recurring.md](recurring.md#cutover): let each iDoklad template run its last occurrence, switch it off in iDoklad, then resume it in Foresi.
6. Keep the iDoklad account read-only for reference until the undo window has passed and the accountant has the period.

## For operators

- Downloads, checks and imports are background jobs on the `async` transport; the worker must consume it (see [import.md](import.md#running-the-worker)). A job a stopped worker left running is done again; a pull and an import bear that.
- The keys are sealed with `MAIL_SECRET_KEYS`, as mail server passwords are; see [sending.md](sending.md) for the keys and their rotation.
- Downloaded PDFs are kept in `var/share/<env>/idoklad-documents` (`app.idoklad_files_dir`), and the originals of imported documents in `var/share/<env>/original-documents` (`app.original_documents_dir`). Both are outside the web root; include them in backups together with the database.
- The snapshot, the links between iDoklad records and Foresi records, and the reports of checks and imports are kept in the database.
- The end-to-end tests seed a stored snapshot and never call iDoklad; their client fails every request.

See [ADR 0024](adr/0024-idoklad-import.md) for the reasons behind the design.
