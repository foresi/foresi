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
- it still owes something, and at least the minimum; an invoice in another currency is reminded whatever its amount;
- it has had fewer than 10 reminders, and there is an address to send to;
- the day has come: the due date plus the first delay for the first reminder, the previous reminder's day plus the next delay after that.

One reminder per invoice per run, so a day the worker missed never sends two at once. Reminder *n* is recorded with its step, unique per invoice, so two runs never send the same step.

A trashed invoice owes nothing and is never reminded.

The reminder uses the firm's **Reminder** template in the document's language, on the templates tab beside the invoice template. Besides the invoice variables it offers the days overdue, the reminder number and the amount due. Reminders run against the same daily budget of the firm as other system sends, which take at most 80% of it.

## The gate: no reminders before payments

Without recorded payments every paid invoice looks unpaid, so a reminder could reach a customer who has paid. Until a payments module tells Foresi what is still owed, **automatic reminders are off and cannot be switched on**: the switch is disabled with "Automatic reminders start once payments are recorded".

What is owed comes from the `InvoiceBalances` port. Its default, `DueDateBalances`, counts the whole payable amount of every invoice outside the trash and says it is not authoritative. A payments module binds its own implementation, which must pass `InvoiceBalancesContractTest`, and answers that it is authoritative; that lifts the gate. The parameter `app.reminders.allow_without_payments` lifts it too, and is true only in the test and end-to-end environments.

While reminders are off, the settings page shows which first reminders the coming 30 days would bring **if they were switched on today**, so the first switch-on has no surprise. Once they are on, it shows which invoices would be reminded tomorrow.

## The contact flag

The contact form has **Send reminders** in its invoicing panel, on by default. Like the other invoicing defaults it is shared by all firms or set per firm. With it off, the contact's invoices get no automatic reminder from that firm; a reminder by hand still goes.

## Reminders by hand

An overdue invoice has **Send reminder** in its row menu and its history. It opens the send dialog with the reminder text and the next step. It counts towards the 10 and appears in the invoice's history and in **Sales › Sent e-mails**, which can be filtered by type.

## Cancellation

A reminder waits in the mail queue like any send. Just before it leaves, under the lock of the invoice, Foresi reads again what the invoice owes and cancels the reminder when:

- the invoice was paid meanwhile (it owes nothing), or
- it was moved to the trash.

A reminder the run queued is also cancelled when the firm switched reminders off or the contact is no longer reminded, and it fails visibly when the contact has no address left. A reminder a person sent by hand goes as they asked, unless the invoice was paid or trashed. A cancelled reminder shows as cancelled in the invoice's history and the send log.
