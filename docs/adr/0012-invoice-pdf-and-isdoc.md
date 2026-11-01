# 12. Invoice PDF and ISDOC

* Status: accepted
* Date: 2026-11-01

## Context and problem statement

An issued invoice has to leave the system as a document a person can read and an accounting program can import. In the Czech Republic that means a PDF, and ISDOC, an open XML format for invoices that accounting programs read. The PDF is also meant to be archived, so it must keep its meaning without the fonts or programs of the day it was made. The engine that draws it must be usable in an AGPL project, run in a plain PHP process without a browser and produce the same bytes of meaning on every machine.

## Decision drivers

* The licence of the engine must allow it in an AGPL-3.0 project that others host.
* No browser, no external service and no process outside PHP are needed to produce a document.
* The PDF must be suitable for long-term archiving and carry its ISDOC file with it.
* What the PDF shows and what the ISDOC file holds must be the same figures.
* A document that cannot be drawn faithfully must be refused, not drawn with a gap.

## Considered options

* tc-lib-pdf (LGPL-3.0-or-later)
* mPDF
* A browser rendering HTML to PDF

## Decision outcome

Chosen option: tc-lib-pdf, because its licence is compatible with AGPL-3.0, it runs inside PHP and it produces PDF/A-3a.

### Engine

* The engine is `tecnickcom/tc-lib-pdf`, constrained to `^8.82.2`, which allows later 8.x versions. All its packages are LGPL-3.0-or-later, which can be combined with AGPL-3.0-only. The licence of every package of the family was read when the dependency was chosen, and `composer audit` was clean. Raising the floor needs the same two checks again; `composer audit` runs in CI for what the range admits.
* mPDF is not used because its licence (GPL-2.0-only) cannot be combined with AGPL-3.0. A browser is not used because it adds a process and a runtime to every installation, and its output changes with its version.
* Only the infrastructure layer of the Invoicing module may use the library (a Deptrac layer rule). The rest of the module sees `InvoicePdf`, which returns bytes and a file name.
* The library may read fonts, its own colour profile and the firm image directory, and nothing else. It may not load a remote address or any other file, whatever the document says.

### PDF/A-3a

* The documents conform to PDF/A-3a, the level that requires tagged structure. Conformance is checked in the tests with veraPDF, whose verdict `PASS` for flavour `3a` is required. In CI the check cannot be skipped. An automated negative control, `PdfEngineTest::testTheConformanceCheckTellsTheLevelsApart`, draws a document that passes as PDF/A-3b but has no tags and requires it to fail as 3a, so the check does tell the levels apart.
* The structure tags come from the library when the HTML is drawn; the QR code is a figure.
* Producer and creator are set to the library and Foresi. Dates are the time of drawing.

### Fonts

* The PDF embeds three faces of Inter 4.1 (SIL Open Font License): `intertnum` and `intertnumb` from Regular and SemiBold, and `interdisplay` from Display SemiBold. The library applies no OpenType features, so in the first two the character map of the digits points at the `tnum` glyphs, which gives tabular figures. `bin/build-pdf-fonts` makes them from the release, with `bin/inter-tabular.py` (Python 3 with fontTools) for the digits, and gives the same files on every run. The converted files and the licence are committed in `resources/pdf-fonts/`. The SHA-256 of the archive and of each face are recorded in the script.
* A character that the font has no glyph for would disappear from the PDF while staying in the ISDOC file, and the two would differ. Such a character is therefore refused when the invoice is saved: the form names the field and the characters. Names, addresses, texts and other values are checked the same way, parts of a customer included. Text in scripts the font does not cover (for example Chinese or Arabic) cannot be issued yet. A fallback font may be added later; until then the refusal is the rule.

### Layout

* Every invoice is drawn with one layout, the panel: a green block around the QR code holds all a client needs to pay, or runs across the page as a band on a document without a code. The template also holds the supplier and customer, dates, items, a summary per VAT rate, the totals, the firm's logo, and its stamp and signature image.
* There is no choice of layout. The data the PDF is drawn from does not depend on it.
* The QR code is QR Platba+F: a payment by the Czech Banking Association standard (SPD) with the invoice by the QR Faktura standard (SID) in its `X-INV` key. A number that the code cannot carry is refused when it is typed, when a series format that could give one is saved, and when a generated number is allocated. A document in the trash has neither the code nor the ISDOC file.

### ISDOC

* The ISDOC file is written by Foresi's own writer for version 6.0.2 of the format and attached to the PDF as an associated file with the relationship `Alternative` and the media type `text/xml`. A bulk download zips these PDFs, each with its ISDOC file embedded; there are no separate ISDOC files.
* The schema package states no licence, so it is not vendored. The tests validate the file against it after `composer isdoc:schema` fetched it and checked its SHA-256. CI requires the check.
* The ISDOC producer statement and the document types and version supported are listed in [docs/invoicing.md](../invoicing.md).
* The rounding of the total is written as `PayableRoundingAmount` and nowhere else; no line and no subtotal is changed for it. The PDF shows the same amount as its own row after the items.
* For a domestic reverse charge the file carries the supply code of each line (`LocalReverseChargeCode`) and the flag on the subtotal (`LocalReverseChargeFlag`), and the note of the document holds the words of the notice. These additions go beyond what the accounting program we compared with writes. Their correctness is subject to professional tax and legal review.

### Bulk download

* The download for the accountant draws every document of a period into one ZIP of PDFs, each with its ISDOC file embedded. If one document cannot be drawn, no ZIP is made and the form names the document. A period is never delivered with a document silently missing.

### Consequences

* Good: licence, runtime and archiving needs are met without a browser.
* Good: the PDF and the ISDOC file are made from the same stored amounts and cannot differ in figures.
* Good: a document that cannot print is refused before it is issued, not found wrong afterwards.
* Bad: an LGPL dependency has obligations of its own; the licence review is repeated when the library's floor is raised.
* Bad: some scripts cannot be used in invoices until a fallback font exists.
* Bad: the tests need veraPDF (Java), Poppler and the schema package for full coverage; they skip locally with a message and are required in CI.
