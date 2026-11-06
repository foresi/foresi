# 23. The accountant pack and the export state of documents

* Status: accepted
* Date: 2026-11-06

## Context and problem statement

Once a month a small firm passes its documents to an external accountant. Until now Foresi offered a ZIP of PDFs for any period and recorded nothing: nobody could tell which documents the accountant already had, a document edited after the handover went unnoticed, and the accountant had to type the amounts from the PDFs. The firm needs one action per month that gives the accountant everything, says what is new since the last time, and is never missing a document silently. The sums of a month must also be visible in the list before anything is sent, and the start page should remind of a month not yet handed over.

## Decision drivers

* The accountant gets every document of a month, by the rules that decide the VAT period.
* A document edited after its export is visible as such, but editing is not blocked: a typo found later must still be fixable the usual way.
* An accounting program imports the files without manual work; a person can still find a document by hand.
* Sums are right for every rate and treatment, and for documents in another currency once those exist.
* Nothing new to operate for the volume of a small firm.

## Considered options

* The export state: two timestamps on the document, "exported at" and "changed after export at", as iDoklad marks it (chosen); a lock that refuses edits after an export; an export batch table with its documents
* The month of a document: the tax point for tax documents and the issue date for advance invoices (chosen); the issue date for all
* Building the pack: synchronously in the request (chosen); a queued job with a notification when it is ready
* The ISDOC file: a separate `.isdoc` next to each PDF (chosen); only embedded in the PDF/A-3
* Sums in the home currency: each document converted at its own exchange rate (chosen); the rate of a single day; per currency only

## Decision outcome

**The export state as timestamps.** A document stores `exportedAt` and `changedAfterExportAt`. A finished pack sets the first and clears the second. The first edit after it sets the second and later edits keep it, so the list can say when the document changed after its export. Edits stay allowed, as in iDoklad: the state is information for the accountant, not a lock. From the two timestamps the state is not exported, exported or changed. A person can clear the mark from the row menu, so the next pack takes the document again. The export and the clearing are written to the document's history. The default pack takes what is not exported or changed; "all" takes the whole month again.

**The month by tax point, advances by issue date.** A tax document belongs to the VAT period of its tax point, so the pack, the month filter of the lists and the overview put it in that month. An advance invoice has no tax point; it goes in the month it was issued, which is where the accountant looks for it. The trash stays out.

**Built synchronously.** A small firm issues about 13 documents a month, so the pack is drawn while the request waits: every document has its own PHP time limit, and the archive is all or nothing, so a document that cannot be drawn stops it and is named. A queue, a store of finished archives and a notification would add work to run for no gain at this size. If a firm's month grows past what a web request can carry, a queued build can replace this behind the same page.

**A separate ISDOC next to the PDF/A-3.** The PDF keeps the ISDOC embedded, but accounting programs import a folder of `.isdoc` files more readily than attachments of a PDF, so each document with an ISDOC file gets one beside its PDF under the same name. Files are named `{issue date}_{customer}_{amount}{Kc or ISO code}_VS{variable symbol}` in a folder per kind, so a person finds a document by hand too. A list (`seznam.xlsx`) states each document's export state before the pack and recaps VAT per treatment and rate.

**Each document at its own rate.** The sums under a list, the recap of the pack and the overview add documents in the home currency, each converted at the exchange rate stored on it, as its own VAT recap states it. The sums then match what the documents say, and no rate of another day is involved.

### Consequences

* Good: the accountant gets one ZIP a month, with only what is new by default, files ready to import and a recap to check them against.
* Good: an edit after the export is visible in the list, in the filter and on the next pack, without blocking a correction of a typo.
* Good: the overview reminds of a month not yet passed on, and links to its pack.
* Bad: the state says that a document went into a pack, not that the accountant received or booked it.
* Bad: a very large month is limited by the web server's timeouts until a queued build exists.
