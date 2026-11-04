# 18. Recurring occurrences

* Status: accepted
* Date: 2026-11-02

## Context and problem statement

A recurring template issues an invoice for each date of its schedule, unattended, and usually sends it to the customer at once. A double invoice and a missing one both reach the customer, so each occurrence must be issued exactly once, whatever runs: the daily job on several workers, a catch-up after downtime, a retry of a day given back, "Issue now" by a person, a template saved or resumed after the day's run, and an edit, pause or end of the template at the same moment. An issued invoice is an ordinary invoice and can go to the trash afterwards. Foresi runs on SQLite and on PostgreSQL.

## Decision drivers

* One invoice per occurrence, on every path that issues, not only the scheduled one.
* An issued invoice holds one whole version of its template, never a mix of an edit and the old lines.
* Nothing is issued after a pause or an end commits.
* The trash must never bring an occurrence back.
* The same guarantee on SQLite and on PostgreSQL, proven by race tests with real processes.

## Considered options

* An occurrence key on the invoice and a lock of the template in the issue transaction (chosen)
* The scheduler's claim of the day alone
* A separate occurrence table

## Decision outcome

Chosen option: **the occurrence key and the lock**.

`invoice` gains `recurring_invoice_id` and `recurring_occurrence`, unique together with `tenant_id` (`uniq_invoice_occurrence`). Both are NULL on invoices issued by hand, and NULLs repeat freely on both databases. `IssueInvoice::issue()` takes an optional `IssueOrigin`, which it calls inside its write transaction once the invoice row exists; throwing aborts the issue. `RecurringOccurrence`, the origin for a template, locks the template row and takes the occurrence only when the template is active, the occurrence is its `nextOccurrence`, no invoice holds it, and the template is still the version the invoice was built from. It then records the occurrence on the invoice and advances the template (issued count, next date or end) in the same commit. Otherwise it throws `OccurrenceTaken` and the whole issue rolls back, the series counter included. A process that skipped the lock still meets the unique key, and its violation maps to `OccurrenceTaken` too. A changed version rebuilds the invoice from the template as it is now, a few times at most, so an invoice never mixes two versions.

Pause, resume, end and edit take the same lock, so a run either sees the change whole or finishes first. A trashed invoice keeps its key, so the occurrence stays taken.

The next date is computed from the anchor, occurrence *n* being the first issue plus *n* intervals with the day clamped to the month, never from the previous date, so months of different lengths cause no drift.

**Period text.** The texts of a template hold period tokens resolved against the occurrence date, so a late issue still names the right month. Tokens are independent, as in iDoklad: each moves the occurrence date by its own offset only and shows its own part of the result, and no offset carries into another token. So `{month+1}/{year}` on 1 December 2026 gives `1/2026`, `{day+1}` on 31 December gives 1 while a plain `{month}` next to it stays 12, and `Services {month}/{year}, due {day+14}.{month}.{year}` on 20 January 2027 gives `Services 1/2027, due 3.1.2027`. Write `{year+1}` to move the year too. The one exception is `{month/year}`, a single token whose offset counts months and moves both parts: `{month/year+1}` on 1 December 2026 gives `1/2027`. `{document_number}` is filled in after numbering, inside the issue, with the number the invoice gets. Anything else in braces stays as typed. Carrying offsets into neighbouring tokens was rejected: imported iDoklad texts would read differently from what iDoklad sent.

**Timing.** The recurring run is a `ScheduledJob` whose day begins at 05:00 in the tenant's zone ([ADR 0016](0016-scheduled-jobs.md), [ADR 0017](0017-tenant-timezone.md)); the reminder run begins at 08:00. A run issues every occurrence from the next date up to today, oldest first, at most 24 per template. An occurrence up to 3 days late is sent; a later one is issued and held for review. Resuming a paused template skips the dates it missed and names them. A template saved or resumed with today's date after today's run has finished is handed to the worker at once and issued and sent through the same path, so the key covers it like any run.

**Sending.** System sends use at most 80% of a firm's daily send budget, so people keep room to send by hand. After the first refusal for the budget in a run, the firm's later templates are issued and held without asking again, and the firm gets one digest alert per run naming every template that failed. A held invoice is "to send" while it is not delivered and has no invoice e-mail queued or sent.

### Rejected options

**The scheduler's claim alone.** `scheduled_job_run` already lets one worker claim a tenant's day. It guards only the scheduled path: "Issue now", the catch-up of a template that fell behind, the issue after today's run and a retry of a day given back halfway all issue outside that claim or for a day already claimed. A crash between the issue and the end of the run would also issue again on the retry. The guarantee has to sit where the invoice is written.

**A separate occurrence table.** A row per issued occurrence, written beside the invoice, keeps the invoice table unchanged. But it is a second record of one fact: trashing, restoring or editing an invoice would have to keep it in step, and a mistake there either issues an occurrence again or blocks it for good. A key on the invoice itself moves with the invoice, trash included, and needs no reconciliation.

### Consequences

* Good: one invoice per occurrence on every path, proven by race tests of two issues, an issue and a pause, and an issue and an edit, on SQLite and PostgreSQL.
* Good: the history and the list can say which template and date an invoice came from without a join to another table.
* Bad: an occurrence issued by mistake cannot be issued again after it is trashed; the user issues an ordinary invoice instead.
* Bad: the invoice table carries two columns that most invoices leave empty.
* Known gap: the invoice list rows still tell overdue by the zone of the firm's country rather than the tenant's, see [ADR 0017](0017-tenant-timezone.md).
