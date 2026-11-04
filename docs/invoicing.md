# Invoices

This page says what the invoices of this release do and do not do. It is a statement of the software, not tax or legal advice: every rule here is subject to professional tax and legal review, and you are responsible for the documents you issue.

## What is supported

- Document type: an invoice. Other types (advance invoice, tax document for a payment, correction) are not in this release.
- Countries: invoices issued by a firm seated in the Czech Republic. The country rules live in a country module, so others can be added.
- VAT regimes: a VAT payer, and a firm that is not a VAT payer (documents without VAT). A firm with the status identified person cannot issue invoices yet.
- Tax treatments per line: standard rate, reverse charge (with its supply code), exempt and not applicable. VAT is not rounded to whole crowns; the total can be rounded to a whole crown, as a setting of the firm.
- Currency: Czech crowns only. Prices in another currency are refused.
- Numbers: series per firm with date tokens and a counter that never repeats a number. A deleted invoice goes to the trash and keeps its number. See ADR 13.
- Output: a PDF/A-3a with the ISDOC file embedded, a QR Platba+F code, and the logo and stamp of the firm. See ADR 12.

## The form

- **Customer.** Any active contact can be picked, whatever its role; a contact archived for the firm cannot. Customers come first. A saved invoice makes the contact a customer of the firm: of the firm alone when it has roles of its own or the organisation has several active firms, otherwise in the shared roles.
- **New customer.** The plus opens the contact form in a new tab, with the register lookup and its validation. The first contact saved there is picked on the invoice with its defaults.
- **My firms.** These are your own companies, which issue documents. Customers belong in the address book. `/invoices/new` explains what is missing when there is no firm, or only archived series. A firm with no series at all gets the standard one on its first save.
- **Register entry.** Required on every firm, as Civil Code section 435 requires it on business documents. A business outside the Commercial Register states its record in another register, such as the trade register.
- **More details.** Order number, description, symbols, language and texts. The section opens when one of them differs from what the form starts with, or holds a mistake. An empty description takes the text of the first line.
- **Numbers.** Prices and quantities are grouped as you type; without JavaScript the server reads the same forms. A separator counts only where it groups thousands properly, and a comma in `1,000` is a decimal mark in Czech and a thousands separator in English.
- **Status.** One derived status per document, the most relevant: in the trash, overpaid, paid, overdue, partly paid, delivered, issued. An invoice is overdue only while something is left to pay.
- **Payments.** "Pay" in the row menu records a payment by hand: date, amount, method and note. A part payment and an overpayment are allowed. Payments from bank notification e-mails are paired by themselves when the rules leave one answer. The invoice lists its payments, each can be removed, and an invoice with payments cannot go to the trash. See [bank-pairing.md](bank-pairing.md).

## ISDOC statement

Foresi produces ISDOC version 6.0.2, document type 1 (invoice), with its own writer. The producer is named in the file. The files are validated against the published schema in the tests. The file uses the extension `LocalReverseChargeCode` and `LocalReverseChargeFlag` for a domestic reverse charge. Which parts of the format other programs read, and how, differs between programs; check the import with yours before relying on it.

## Limits of this release

- The PDF has one layout, the panel; there is no choice of layout.
- Text outside the bundled font (Inter) is refused: for example Chinese or Arabic names and texts cannot be issued.
- An invoice has at most 100 lines, and no amount has more than twelve digits before the decimal point.
- A number has at most 40 characters and no `*` or `%`, or its QR code could not be made. A series whose numbers could be longer cannot be saved.
- The bulk download records nothing: which documents went to the accountant is not tracked. An export state arrives in a later release.
- A document in the trash prints with a mark, without the QR code and without the ISDOC file.
- Foreign tax regimes are not included. Sending by e-mail is described in [sending.md](sending.md), payments and bank pairing in [bank-pairing.md](bank-pairing.md), recurring invoices in [recurring.md](recurring.md) and automatic reminders, which stay off until payments are recorded, in [reminders.md](reminders.md).
- Tax documents on payment, advances, bulk payment and payments in another currency than the invoice's are not included. Bank notifications are read for Komerční banka only.
- The rounding rules, the reverse charge and the numbering are implemented as described in the ADRs; whether they are right for your situation is for your adviser to confirm.

## Bulk download and time limits

The download for the accountant draws one PDF after another, so its length grows with the period. It needs no limit raised for that: PHP's `max_execution_time` is reset to 30 seconds for each document and to 300 seconds for closing the archive, so a year of invoices finishes while one stuck document still ends the request.

A web server or proxy in front of PHP counts on its own and knows nothing of this. Set the timeouts there (`request_terminate_timeout` of PHP-FPM, `proxy_read_timeout` or `fastcgi_read_timeout` of nginx) to the longest period you expect, at about a tenth of a second per document, or use a shorter period. A download cut there leaves no half file: the browser reports a failure.

A document that cannot be drawn stops the archive, and the form names it: the accountant never gets a period with a document silently missing.
