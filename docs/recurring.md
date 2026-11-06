# Recurring invoices

A recurring invoice is a template that issues an ordinary invoice, or an advance invoice when its series is one of advance invoices, on a schedule, for example a hosting fee every month or a licence every year. Each issued invoice is a normal invoice: it has its number from the series, it can be edited, sent again or moved to the trash under the usual rules. The template itself holds no number and is never a document. Recurring invoices are under **Sales › Recurring invoices**.

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
| Tax point | issue date, or last day of the issue month | For a firm that is not a VAT payer the same choice is the date of supply. An advance invoice has none; its rates are those of its issue date. |
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

The move of a whole account, its steps and its limits are in [idoklad.md](idoklad.md); this section covers its recurring invoices.

The iDoklad import (its recurring stage, after contacts and number series) turns each iDoklad recurring invoice into a template with `SaveRecurringInvoice::import($input, new RecurringHistory(<iDoklad id>, <issued>, <last issue>))`. The template is stored **paused**, remembers the iDoklad id, and carries over what iDoklad issued: the count and the date of the last issue. Only this path accepts a first issue date in the past; a template created by hand still needs one from today on. The import is linked, so a second run leaves the template alone.

### Field mapping

The names are those of the iDoklad API v3 (`RecurringInvoice` with its `RecurringSetting` and `InvoiceTemplate`).

| iDoklad | Foresi |
|---|---|
| `RecurringSetting.TemplateName` | Name |
| `DateOfStart` | First issue, even when it has passed |
| `RecurrenceType` (days, weeks, months, years) with `RecurrenceCount` | Repeat daily, weekly, monthly or yearly, every that many. Yearly stays yearly; a monthly template with count 12 stays "every 12 months" |
| `IssueLastDayOfMonth` | Issue on the last day of the month (monthly and yearly) |
| `TypeOfEnd` (never, on a date, after a number of issues) with `DateOfEnd` or `CopyCountEnd` | End repeating never, with the last issue on or before the date, or after the number of issues |
| `CopyCountActual`, `DateOfLastIssue` | Issued count and last issue, carried over; an end after a count goes on from the carried count, and a template whose end rule allows no more is stored ended |
| `DateOfNextIssue` | Must be the next date Foresi works out from the last issue, or the template is refused with both dates (`next_issue_mismatch`). The report also holds the next five issues, to compare with iDoklad's preview |
| `IsActive` false | Imported paused like the others, with a note; no next date is compared |
| `InvoiceTemplate.TaxingType` `DayOfIssue` / `LastDayOfMonth` | Tax point: issue date / last day of the issue month |
| `InvoiceMaturity` | Due |
| `Partner.Id`, `NumericSequenceId` | The customer and the series the earlier stages imported; a template whose customer or series was not imported is refused |
| `Items`: `Name`, `Amount`, `Unit`, `Prices.UnitPrice`, `PriceType`, `VatRateType` (first reduced, basic, zero, second reduced), with the template's `DiscountPercentage` | Lines: text, quantity, unit, price, price type, rate kind (reduced, base, an exempt supply, second reduced) and discount. A firm that is not a VAT payer gets lines without VAT. The rounding item is left out with a note, as Foresi rounds by the firm's setting |
| `ItemsTextPrefix`, `ItemsTextSuffix`, `Description`, `OrderNumber` | Text before and after the lines, description, order number |
| iDoklad variables in these texts and the item names | Kept as they are: iDoklad's `{day}`, `{month}`, `{year}`, `{quarter}`, `{month/year}` with their offsets and `{document_number}` are Foresi's tokens and resolve the same way. Any other variable refuses the template (`unknown_variable`), so nothing prints as typed |
| `PaymentOptionId`, `BankAccountId`, `ConstantSymbolId`, `CurrencyId` | Read through the code lists the pull copies: the payment method by the option's name (transfer, cash, card), the IBAN of the iDoklad account or else the firm's primary one, the constant symbol's code, and a currency that must be the firm's home currency |
| `ReportLanguage` | Czech or English |
| `SendToPurchaser` | After the issue: send, or hold for review |
| `SendToSupplier`, `SendToOtherEmails` | Copy to me, copy to the contact's other addresses |
| `SendToAccountant`, `Note`, `IsConstantVariableSymbol` | No equivalent: a note on the entry, the template is imported |
| `InvoiceTemplate.DocumentType` 1 (advance invoice) | Refused and counted (`idoklad.import.recurring.advance_unsupported`); see Limits |

Also refused, with the reason in the report: a foreign currency, reverse charge (`VatReverseChargeCodeId`), another language, a payment option Foresi has no method for, an item Foresi cannot take, and anything the rules of a template refuse, such as a rate the next tax point does not have.

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
