# Invoices

This page says what the invoices of this release do and do not do. It is a statement of the software, not tax or legal advice: every rule here is subject to professional tax and legal review, and you are responsible for the documents you issue.

## What is supported

- Document type: an invoice. Other types (advance invoice, tax document for a payment, correction) are not in this release.
- Countries: invoices issued by a firm seated in the Czech Republic. The country rules live in a country module, so others can be added.
- VAT regimes: a VAT payer, and a firm that is not a VAT payer (documents without VAT). A firm with the status identified person cannot issue invoices yet.
- Tax treatments per line: standard rate, reverse charge (with its supply code), exempt and not applicable. VAT is not rounded to whole crowns; the total can be rounded to a whole crown, as a setting of the firm.
- Currency: Czech crowns only. Prices in another currency are refused.
- Numbers: series per firm with date tokens and a counter that never repeats a number. A deleted invoice goes to the trash and keeps its number. See ADR 13.
- Output: a PDF/A-3a with the ISDOC file attached, a QR Platba+F code, and the logo and stamp of the firm. See ADR 12.

## ISDOC statement

Foresi produces ISDOC version 6.0.2, document type 1 (invoice), with its own writer. The producer is named in the file. The files are validated against the published schema in the tests. The file uses the extension `LocalReverseChargeCode` and `LocalReverseChargeFlag` for a domestic reverse charge. Which parts of the format other programs read, and how, differs between programs; check the import with yours before relying on it.

## Limits of this release

- The layout of the PDF is one fixed layout and not final.
- Text outside the bundled font (Inter) is refused: for example Chinese or Arabic names and texts cannot be issued.
- An invoice has at most 100 lines.
- Sending by e-mail, payments, reminders, recurring invoices and foreign tax regimes are not included.
- The rounding rules, the reverse charge and the numbering are implemented as described in the ADRs; whether they are right for your situation is for your adviser to confirm.

## Bulk download and time limits

The download for the accountant draws one PDF after another, so its length grows with the period. It needs no limit raised for that: PHP's `max_execution_time` is reset to 30 seconds for each document and to 300 seconds for closing the archive, so a year of invoices finishes while one stuck document still ends the request.

A web server or proxy in front of PHP counts on its own and knows nothing of this. Set the timeouts there (`request_terminate_timeout` of PHP-FPM, `proxy_read_timeout` or `fastcgi_read_timeout` of nginx) to the longest period you expect, at about a tenth of a second per document, or use a shorter period. A download cut there leaves no half file: the browser reports a failure.

A document that cannot be drawn stops the archive, and the form names it: the accountant never gets a period with a document silently missing.
