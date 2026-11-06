# Invoices

This page says what the invoices of this release do and do not do. It is a statement of the software, not tax or legal advice: every rule here is subject to professional tax and legal review, and you are responsible for the documents you issue.

## What is supported

- Document types: an invoice, an advance invoice, a tax document on a received payment and a corrective tax document (credit or debit note). See [Advances](#advances), [Corrections](#corrections), ADR 21 and ADR 22.
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

## Advances

- **Advance invoice.** Sales, Advance invoices, New. It asks for payment before the supply and is no tax document: it has no tax point, its VAT rates are those of its issue date, its PDF says under the VAT recap that the recap is informative only, its QR code carries no tax fields, and it has no ISDOC file. It numbers in its own series (standard `Z9{YYYY}{NNNN}`), is sent with its own mail template, and is paid and paired like an invoice. Reminders take it when the firm switches that on.
- **Tax document on payment.** Every payment recorded on an advance, by hand or from the bank, gets a tax document in the same step: its own series (standard `D8{YYYY}{NNNN}`), the advance's variable symbol, the day of receipt as its tax point, and the amount received split over the rates of the advance in proportion, VAT taken out of the gross amount. It says it is already paid, has no QR code, and is never paired, reminded or overdue; the page of its link says not to pay it either, with no due date and no way to pay. It cannot be edited; it follows its payment.
- **When VAT arises.** A firm that is no VAT payer, a customer who is a private person and an advance whose every line is a domestic reverse charge get no tax document. On the advance, "Tax document on payment" can say the supply is not known yet (none) or ask for the documents anyway; the form shows what the rules say for a saved advance. The deadline is 15 days from the payment, or 15 days from the end of its month when every line is a reverse charge to a customer abroad.
- **Waiting for a tax document.** A tax document a rule refused (an archived standard series, say) leaves the payment recorded. The advance then waits in Advance invoices, Needs tax document, with the days left, and a notice on every page names the first deadline. "Issue tax document" issues it there.
- **Cancelled or refunded payment.** Its tax document goes to the trash while the customer has not got it, and cannot be restored from there; after a part refund a new one taxes what stays paid. A delivered one gets a corrective tax document in the same step; see [Corrections](#corrections).
- **Settling.** On a new or edited invoice, "Deduct an advance" offers the customer's tax documents and the paid advances on which no VAT arose. Each adds negative lines, one per rate, so the VAT recap is net of the advances. A document is deducted by one invoice only; trashing the invoice frees it. The total may reach zero or less: the invoice then has no QR code, says it is settled by advances, and a negative total is a difference to refund; the page of its link says the same and asks for no payment. The ISDOC file carries the deposits per tax document and rate.
- **Unsettled.** Advance invoices, Unsettled lists the paid advances no final invoice has deducted in full yet. Tax documents on payment have the tabs All, Settled, Unsettled and Trash instead of the payment tabs: a tax document is always paid, and Unsettled holds what a final invoice may still deduct.
- **Links.** Each document of a chain shows the advance, its payments with their tax documents, and the invoice that deducts them.
- **Accountant.** The pack for the accountant is one firm and one month: tax documents by tax point, advance invoices, which have none, by issue date. It holds a folder per kind, each document as a PDF with its ISDOC beside it, and a list (XLSX) with a VAT recap per treatment and rate in the home currency.

## Corrections

- **Credit and debit notes.** "Issue a corrective document" in the row menu of an issued invoice opens the form of a corrective tax document: every line negated at the invoice's rates and treatments, the same firm and customer, the link to the invoice, and fields for the reason, the type and the decisive date. Lower the lines to credit part of it. A total below zero is a credit note, above zero a debit note. Its own series (standard `O7{YYYY}{NNNN}`) and its own mail template, which knows the number of the original.
- **Type.** A change after the supply (§42) is taxed on its decisive date; an error on the document (§43) on the tax point of the original.
- **Cap.** Per rate and treatment, an invoice and its corrections never go below zero, checked again under a lock when two people save at once. A trashed correction frees its amount.
- **What is left to pay.** The invoice's total plus its corrections outside the trash, less what was paid, plus refunds. A fully credited unpaid invoice is settled. An invoice paid above what is now due is to refund: its status says "To refund", the invoice list and Corrections have a To refund tab, and the payment panel offers "Record refund", filled in with what is to refund. A correction itself is never owing, overdue, reminded or paired.
- **Output.** The PDF is titled corrective tax document, with credit note or debit note under it, the corrected number, the reason, the decisive date and a box of differences in base, tax and total. A credit note shows what goes back, with no due date, no account and no QR code, in the PDF, on the page of its link and in its send; a debit note asks for payment like an invoice.
- **Tax documents on payment.** Cancelling or refunding a payment of an advance corrects its delivered tax documents by itself, at the rates of the receipt, dated on the day of the cancellation or refund. A document a final invoice deducts, or one a rule refused, waits under Tax documents, Waiting for correction, with the days left to the 15-day deadline, and a notice names the first one. A delivered tax document can also be corrected by hand from its row menu.
- **List.** Sales, Corrections, with the tabs All, To refund and Trash.

## ISDOC statement

Foresi produces ISDOC version 6.0.2 with its own writer: document type 1 (invoice), 2 (credit note), 3 (debit note) and 6 (correction of a tax document on payment). A corrective document names its original in `OriginalDocumentReference` with its number, issue date and UUID, and states its own signed amounts. Advance invoices and tax documents on payment have no ISDOC file. A final invoice lists the supply as its lines and the deducted advances as `TaxedDeposits` (tax documents, per rate) and `NonTaxedDeposits` (advances without VAT), with the per-rate `AlreadyClaimed` and `Difference` amounts. The producer is named in the file. The files are validated against the published schema in the tests. The file uses the extension `LocalReverseChargeCode` and `LocalReverseChargeFlag` for a domestic reverse charge. Which parts of the format other programs read, and how, differs between programs; check the import with yours before relying on it.

## Limits of this release

- The PDF has one layout, the panel; there is no choice of layout.
- Text outside the bundled font (Inter) is refused: for example Chinese or Arabic names and texts cannot be issued.
- An invoice has at most 100 lines, and no amount has more than twelve digits before the decimal point.
- A number has at most 40 characters and no `*` or `%`, or its QR code could not be made. A series whose numbers could be longer cannot be saved.
- A finished pack marks its documents exported; by default the next pack takes only documents never exported or edited after their export.
- A document in the trash prints with a mark, without the QR code and without the ISDOC file.
- Foreign tax regimes are not included. Sending by e-mail is described in [sending.md](sending.md), payments and bank pairing in [bank-pairing.md](bank-pairing.md), recurring invoices in [recurring.md](recurring.md) and automatic reminders, which follow the recorded payments, in [reminders.md](reminders.md).
- An offset of a credit note against another invoice, a correction for a rate change between an advance and its final invoice, the correction of tax on bad debts, ISDOC types 4 and 5, bulk payment and payments in another currency than the invoice's are not included. Bank notifications are read for Komerční banka only.
- The rounding rules, the reverse charge and the numbering are implemented as described in the ADRs; whether they are right for your situation is for your adviser to confirm.

## Bulk download and time limits

The download for the accountant draws one PDF after another, so its length grows with the period. It needs no limit raised for that: PHP's `max_execution_time` is reset to 30 seconds for each document and to 300 seconds for closing the archive, so a year of invoices finishes while one stuck document still ends the request.

A web server or proxy in front of PHP counts on its own and knows nothing of this. Set the timeouts there (`request_terminate_timeout` of PHP-FPM, `proxy_read_timeout` or `fastcgi_read_timeout` of nginx) to the longest period you expect, at about a tenth of a second per document, or use a shorter period. A download cut there leaves no half file: the browser reports a failure.

A document that cannot be drawn stops the archive, and the form names it: the accountant never gets a period with a document silently missing.
