# Automatic reminders

Foresi can remind customers of overdue invoices by e-mail, through the same sending path as invoices (see [sending.md](sending.md)). Each firm decides whether it reminds and when, under **My firms › (firm) › E-mails**, and each contact can be left out.

## The rule

A firm's reminder settings hold:

| Setting | Default | Values |
|---|---|---|
| Send reminders automatically | off | on or off |
| First reminder | 7 days | 1 to 365 days after the due date |
| Next reminders | 7 days | 1 to 365 days after the previous reminder |
| Minimum amount | none | an amount in the firm's home currency, or blank |

At most 10 reminders go out per invoice, counting those sent by hand.

The reminder run starts at **08:00** in the organisation's time zone every day (see [recurring.md](recurring.md#the-organisations-time-zone) for the zone). An invoice is reminded on a day when all of these hold:

- it is outside the trash and was delivered (sent, or marked delivered);
- it fell due on or after the day reminders were switched on, so switching on never sends a wave about old invoices;
- its contact is reminded for this firm (below);
- it still owes something after its recorded payments, and at least the minimum; an invoice in another currency is reminded whatever its amount;
- it has had fewer than 10 reminders, and there is an address to send to;
- the day has come: the due date plus the first delay for the first reminder, the previous reminder's day plus the next delay after that.

One reminder per invoice per run, so a day the worker missed never sends two at once. Reminder *n* is recorded with its step, unique per invoice, so two runs never send the same step.

A trashed invoice owes nothing and is never reminded. An invoice paid in full, or overpaid, owes nothing either and is never reminded, by hand or by the run.

The reminder uses the firm's **Reminder** template in the document's language, on the templates tab beside the invoice template. Besides the invoice variables it offers the days overdue, the reminder number and the amount due, which is what is left to pay. Reminders run against the same daily budget of the firm as other system sends, which take at most 80% of it.

## What is owed

What an invoice still owes is its total to pay less its recorded payments, the written-off differences included, and nothing once it is in the trash or paid in full; see [bank-pairing.md](bank-pairing.md). A payment removed again counts no more, so the invoice is due for its next reminder once more.

A part-paid invoice is reminded of what is left: the amount due in the text, and the PDF the reminder links to, whose payment box says **Left to pay** with that amount, and whose QR code asks for it. The totals of the PDF stay those of the invoice, and so does the ISDOC file. A payment recorded while the reminder is drawn makes Foresi draw it again. An invoice sent as an invoice still asks for its whole total.

## The gate: no reminders without payments

Without recorded payments every paid invoice would look unpaid, so a reminder could reach a customer who has paid. What is owed comes from the `InvoiceBalances` port, and automatic reminders can be switched on only while it is authoritative. Foresi binds `PaymentBalances`, which reads the recorded payments, passes `InvoiceBalancesContract` and is authoritative, so the switch is available. An implementation that cannot tell a paid invoice from an open one says it is not authoritative; then **automatic reminders are off and cannot be switched on**: the switch is disabled with "Automatic reminders start once payments are recorded".

While reminders are off, the settings page shows which first reminders the coming 30 days would bring **if they were switched on today**, so the first switch-on has no surprise. Once they are on, it shows which invoices would be reminded tomorrow. Both lists leave out what is paid in full and show what is left to pay.

## The contact flag

The contact form has **Send reminders** in its invoicing panel, on by default. Like the other invoicing defaults it is shared by all firms or set per firm. With it off, the contact's invoices get no automatic reminder from that firm; a reminder by hand still goes.

## Reminders by hand

An overdue invoice has **Send reminder** in its row menu and its history. It opens the send dialog with the reminder text and the next step. It counts towards the 10 and appears in the invoice's history and in **Sales › Sent e-mails**, which can be filtered by type.

## Cancellation

A reminder waits in the mail queue like any send. Just before it leaves, under the lock of the invoice, Foresi reads again what the invoice owes and cancels the reminder when:

- the invoice was paid meanwhile (it owes nothing), or
- it was moved to the trash.

A reminder the run queued is also cancelled when the firm switched reminders off or the contact is no longer reminded, and it fails visibly when the contact has no address left. A reminder a person sent by hand goes as they asked, unless the invoice was paid or trashed. A cancelled reminder shows as cancelled in the invoice's history and the send log.
