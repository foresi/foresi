# Recurring invoices

A recurring invoice is a template that issues an ordinary invoice on a schedule, for example a hosting fee every month or a licence every year. Each issued invoice is a normal invoice: it has its number from the series, it can be edited, sent again or moved to the trash under the usual rules. The template itself holds no number and is never a document. Recurring invoices are under **Sales › Recurring invoices**.

This page describes what a template holds, when its invoices are issued and sent, what happens when something goes wrong, and how templates from iDoklad map onto it.

## What a template holds

The form is the invoice form without a number and without dates: the firm, the customer, the series, the payment method and bank account, the constant symbol, the order number, the description, the lines, the language and the texts before and after the lines. "Recurring invoice" in the row menu of an invoice opens the form filled from that invoice.

The schedule:

| Field | Values | Notes |
|---|---|---|
| First issue | a date, today or later | The first occurrence. Every later one is counted from it. |
| Repeat | daily, weekly, monthly, yearly | |
| Repeat every | 1 to 99 | 3 with monthly means every third month. Yearly and "every 12 months" give the same dates but stay distinct. |
| Issue on the last day of the month | yes or no | Monthly and yearly only. Every occurrence then falls on the last day of its month. |
| Tax point | issue date, or last day of the issue month | For a firm that is not a VAT payer the same choice is the date of supply. |
| Due | 0 to 365 days | Counted from the issue date. |
| End repeating | never, on a date (that day included), after a number of issues (1 to 999) | |
| After the issue | send to the customer, or hold for my review | Send is the default. |

The form shows the next five issues with their dates and the description as it will read, and updates as the schedule changes.

### Dates of the occurrences

Occurrence *n* is the first issue plus *n* times the interval, always counted from the first issue and never from the previous date, with the day clamped to the length of the month. A template starting on 31 January 2027, monthly, issues on 28 February, 31 March, 30 April and so on: it never drifts to the 28th. A yearly template starting on 29 February 2028 issues on 28 February 2029 and on 29 February 2032.

The invoice of an occurrence is issued on the day the run issues it, in the organisation's time zone. Its tax point follows the template's rule from that day, and it is due the template's number of days later. The period variables (below) name the occurrence, not the issue day, so an invoice issued late still names the right month.

### Variables in the texts

The order number, the description, the line texts and the texts before and after the lines can hold period variables. They are resolved against the occurrence date. The form shows them as chips; in storage they are English tokens:

| Token | Gives, for the occurrence 1 March 2027 |
|---|---|
| `{day}` | `1` |
| `{month}` | `3` |
| `{year}` | `2027` |
| `{quarter}` | `1` |
| `{month/year}` | `3/2027` |
| `{document_number}` | the invoice's own number, for example `20270042` |

Numbers have no leading zero. Each period token takes an optional offset, such as `{month+1}`, `{year-1}` or `{day+14}`.

**Tokens are independent.** Each token moves the occurrence date by its own offset only and shows its own part of the result; no offset carries into another token. So on 1 December 2026:

- `{month+1}/{year}` gives `1/2026`, not `1/2027`. Write `{month+1}/{year+1}` when the year should move too.
- `{day+1}` on 31 December gives `1`, while a plain `{month}` next to it stays `12`.
- `Services {month}/{year}, due {day+14}.{month}.{year}` on 20 January 2027 gives `Services 1/2027, due 3.1.2027`.

The one exception is `{month/year}`, a single token whose offset counts months and moves both parts: `{month/year+1}` on 1 December 2026 gives `1/2027`. This is how iDoklad resolves its variables, so imported texts read the same.

`{document_number}` is filled in after the invoice is numbered, so it is the number the invoice actually gets; the preview shows `[invoice number]` in its place. Anything else in braces stays as typed, for example `{foo}` or an unclosed `{month`.

## When invoices are issued

The recurring run starts at **05:00** in the organisation's time zone, every day, so invoices due on the 1st leave early that morning. It is one of the timed jobs the worker runs (see [ADR 0016](adr/0016-scheduled-jobs.md)); the worker must consume `scheduler_foresi`, `mail` and `async`, as described in [sending.md](sending.md#the-running-worker).

For each active template whose next date has come, the run issues every occurrence from that date up to today, oldest first, at most 24 per template in one run; the rest follow the next day.

- **Catch-up.** When the worker was down, the next run issues each missed occurrence once. An occurrence up to **3 days late** is still sent as usual. A later one is issued and **held for review**, since the customer no longer expects it without a look. A run on 3 January that finds the occurrences of 1 and 2 January issues both, each with a number from the 2027 series, and sends both.
- **Saving or resuming after today's run.** A template saved or resumed with today's date after today's run has finished is issued and sent at once, within minutes, through the same path as the run. Before 05:00 the form says it will be issued today at 5:00.
- **Pausing and resuming.** Nothing is issued while a template is paused. Resuming skips the dates missed while it was paused and moves on to the first date on or after today; the resume dialog names the skipped dates. A skipped date is not issued later; issue it by hand as an ordinary invoice if it is still owed.
- **Issue now.** The row menu issues the template's next occurrence at once, with today's dates, and sends or holds it as the template says. The run then moves on to the occurrence after it.
- **Ending.** A template ends by its end rule after its last occurrence, or by hand. Ending is final.
- **Deleting.** Only a template that has never issued an invoice can be deleted; one that has is ended instead, so its invoices keep where they came from.

### The organisation's time zone

The day and the 05:00 hour follow the organisation's time zone, which by default is the zone of the country of its first active firm (`Europe/Prague` for a Czech firm), else UTC. The operator can set it:

```bash
php bin/console app:tenant:timezone <tenant-id> Europe/Prague
php bin/console app:tenant:timezone <tenant-id> --clear
```

Only canonical names of the tz database are accepted. See [ADR 0017](adr/0017-tenant-timezone.md). The rows of the invoice list still tell overdue by the zone of the issuing firm's country rather than the organisation's zone, a known gap described in that ADR.

### Each occurrence exactly once

An invoice issued from a template carries the template and the occurrence date, unique per organisation (`uniq_invoice_occurrence` on `tenant_id`, `recurring_invoice_id`, `recurring_occurrence`). Inside the issue transaction the template is locked, and the occurrence is taken only while the template is active, the occurrence is its next date and the template is still the version the invoice was built from. Two workers, a catch-up, a retry and "Issue now" on the same date therefore issue one invoice. An invoice moved to the trash keeps its occurrence, so the trash never causes a second issue. See [ADR 0018](adr/0018-recurring-occurrences.md).

An edit, a pause or an end that commits while a run is issuing is either seen whole or not at all: the invoice holds one whole version of the template, and nothing is issued after a pause commits.

## Sending

With "Send to the customer", the invoice is sent as the send dialog would send it: to the contact's invoicing addresses for the firm, with the firm's invoice template in the document's language, and a copy to the firm's contact address, or to the template's owner when the firm has none. The send is the system's, made on behalf of the owner. Everything else about the send, its link, retries and states, is as in [sending.md](sending.md).

System sends use at most **80%** of a firm's daily budget of 500 sends, so a run never takes the whole day from the people who send by hand. Once the budget refuses a send, the firm's later templates of that run are issued and held without trying again.

### To send

**Invoices › To send** lists invoices issued from a template that are not delivered and have no invoice e-mail queued or sent. A failed send leaves an invoice there. It holds:

- invoices of templates set to "Hold for my review";
- occurrences issued more than 3 days late;
- invoices whose send was refused: no mail server, no recipient, or the budget spent.

Send them from there, or mark them delivered.

## When something goes wrong

A template whose world moved, for example a customer archived for the firm, a contact without an invoicing address, a kind of VAT rate gone on the new tax point or a firm without a mail server, is never skipped in silence:

- The refusal is recorded on the template, which keeps its next date, so the next run tries again. The list shows the reason on the row, next to "Issue now". A template that runs clean again clears it.
- The firm gets an e-mail, to its contact address or else the template's owner, through its own mail server, in the language of the template's documents. It is **one digest per firm per run**, naming every template that failed in it, and each template is mentioned at most once a day.
- Every page shows a notice for failing templates and for invoices waiting under To send.
- When today's run has not finished by **07:00** while templates are due, a notice says so: most likely the worker or the scheduler is not running.

An unexpected error while issuing gives the day back to the scheduler, which retries it on the next tick; what was issued stays issued and is not issued again.

## Moving from iDoklad

The import of recurring templates is a later step. When it is built, each iDoklad template is created with `SaveRecurringInvoice::create($input, startPaused: true, importedFrom: <iDoklad id>)`, so it is stored **paused** and remembers where it came from.

### Field mapping

*The iDoklad names below come from its public API and must be verified against the API when the import is built.*

| iDoklad | Foresi |
|---|---|
| `DateOfStart` | First issue, when no occurrence was issued yet |
| `RecurrenceType` (day, week, month, year) | Repeat: daily, weekly, monthly, yearly. Yearly stays yearly; a monthly template with count 12 stays "every 12 months" |
| `RecurrenceCount` | Repeat every |
| iDoklad's "issue on the last day of the month" option | Issue on the last day of the month |
| `TypeOfEnd` (never, on a date, after a number of issues) with its date or count | End repeating with the last issue or the number of issues. A count is reduced by the occurrences iDoklad already issued, or the issued count is carried over |
| `TaxingType` `DayOfIssue` / `LastDayOfMonth` | Tax point: issue date / last day of the issue month |
| `NextIssueDates` | Checked against the occurrences Foresi computes; the first of them sets the next date. A mismatch is reported for the template, not guessed |
| Due days of the template | Due |
| Invoice data of the template (customer, lines, texts, symbols, series) | The same fields of the template; the customer and the series are matched to records imported before |
| iDoklad variables in the texts | The same tokens, which resolve the same way (independent, `{month/year}` counting months). A variable for the document number maps to `{document_number}` |
| Whether the template sends to the customer | After the issue: send, or hold for review |

### Cutover

A template must never be live in both systems at once, or the customer gets two invoices, nor in neither, or the customer gets none. So:

1. Import the templates paused.
2. Compare each one's next five issues in Foresi with iDoklad's future-issues preview.
3. Let each template's iDoklad twin run its last occurrence, then switch it off in iDoklad.
4. Resume the template in Foresi after that last run. The resume dialog names any dates it skips; there should be none.

### Checklist for the switch day

- The worker runs and consumes `scheduler_foresi`, `mail` and `async`; the notice about an unfinished run stays away after 07:00.
- The organisation's time zone is right (`app:tenant:timezone`).
- Every firm that sends has a working mail server and a contact address; send a test e-mail.
- Every customer of a template has an invoicing e-mail for the firm.
- The series of each template exists and takes the new year's numbers, if the switch is near 1 January.
- Templates whose quantities change each period are set to "Hold for my review".
- The first run is watched: check **To send** and the notices that morning.

## Limits

- Templates are in the firm's home currency only.
- No recurring advance invoices yet, and no price list picking in the template.
- The time zone is set from the console only.
