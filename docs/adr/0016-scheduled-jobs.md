# 16. Scheduled jobs

* Status: accepted
* Date: 2026-11-02

## Context and problem statement

Some work must happen without a request: handing back sends whose queue message was lost, and later reminders and recurring invoices. Each organisation is a tenant, its day follows its country, and a job must run once per period even with several workers, catch up after downtime, and try again after a failure. Foresi already needs a background worker for imports and mail.

## Decision drivers

* Nothing more to install than the worker that sending needs anyway.
* At most one run per job, tenant and period, also with several workers.
* A failed run is retried; a missed period can be caught up.
* Unattended: reminders must run without anyone signing in.

## Considered options

* `symfony/scheduler` consumed by the normal worker (chosen)
* System cron calling a console command
* Checking due work after a user signs in

## Decision outcome

Chosen option: one schedule, `foresi`, ticking every 5 minutes, `stateful` in `cache.app`, with a `lock` and `processOnlyLastMissedRun(true)`, consumed by the worker as the transport `scheduler_foresi`. The tick only fans out: for each tenant and each service tagged `app.scheduled_job` it dispatches a run to the `async` queue.

A job implements `ScheduledJob { name(); period(now); run(?lastPeriod) }`. A run first claims its job, tenant and period in `scheduled_job_run` by one atomic insert; a second worker finds the claim and does nothing. A failed run gives its period back, so the next tick retries it, and `lastPeriod` lets a job catch up on the periods it missed. A daily job computes its period from its tenant's today in the tenant's time zone, not UTC: the tick passes each tenant's zone to `period(now, zone)`, see [ADR 0017](0017-tenant-timezone.md). `scheduled_job_run` is not audited, like `series_counter`.

System cron was rejected: it is a second thing to install and watch beside the worker. Checking after sign-in was rejected: reminders must run when nobody signs in.

### Consequences

* Good: one process to run; periods are exact per tenant; a crash or downtime is made up.
* Bad: without the worker nothing timed happens, which the send notices make visible. The schedule's lock uses `LOCK_DSN`; the default file lock suits one host, and several hosts need a shared store.
