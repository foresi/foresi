# 17. Tenant time zone

* Status: accepted
* Date: 2026-11-02

## Context and problem statement

Scheduled jobs ([ADR 0016](0016-scheduled-jobs.md)) do daily work: issuing recurring invoices and sending reminders. Which day it is, and when it begins, must follow the organisation, not the server or UTC. Until now the only zones known were those of the countries' invoicing rules, reached through a firm, and an organisation can have several firms. A tick visits every tenant without activating any, so finding the zone must stay cheap however many tenants there are.

## Decision drivers

* One day per organisation: its recurring invoices and reminders run on the same day, whichever firm issues them.
* The local wall clock decides: a day begins at the same local hour also on the days summer time starts or ends.
* No setting to fill before anything works; an operator can still correct the zone.
* A tick reads the zones of all tenants in a fixed number of queries.
* Tenancy, the base module, does not depend on the modules built on it.

## Considered options

* A time zone per tenant, falling back to the country of its first active firm (chosen)
* A time zone per firm
* The zone of the firm's country only, with no setting

## Decision outcome

Chosen option: `tenant.timezone`, a nullable zone name of the tz database (`VARCHAR(64)`), set by the operator with `app:tenant:timezone <tenant> <zone>` or removed with `--clear`. Only canonical names of `DateTimeZone::listIdentifiers()` are stored: offsets and abbreviations are refused, as they know no summer time, and old aliases such as `US/Eastern` are refused, as a later PHP may drop them. Every change by the command is logged with the old and the new zone. A stored zone that PHP no longer knows counts as unset, so the tenant follows its firm's country, and each read logs a warning until the operator sets the zone again. Existing tenants are not backfilled: an empty zone means the zone of the country of the tenant's first active firm, the oldest by its time-ordered id, as the country's invoicing rules name it. A tenant without an active firm, or whose firm's country has no rules, is in UTC.

`TenantDirectory::zones()` reads every tenant with its stored zone in one query and asks the port `TenantZoneFallback` once for the rest, so a tick costs two queries. The port lives in Tenancy; Invoicing implements it as `FirmCountryZones`, since it knows firms and their countries' rules. Deptrac allows Invoicing infrastructure to implement the Tenancy port and never the reverse.

The tick passes each tenant's zone to `ScheduledJob::period(now, zone)`. A daily job takes its period from `DailyPeriod::of(now, zone, startHour)`: the local date, which begins at the start hour of the local clock, read off the clock rather than counted back from now, so 05:00 stays 05:00 on a day of 23 or 25 hours. A job on UTC periods, such as the requeue of stuck sends, ignores the zone. `TenantClock` gives the zone and today's midnight in the active tenant for the work a run does.

A zone per firm was rejected: a tenant would have as many days as firms, a run of its jobs could not be one period, and two firms of one organisation would issue and remind on different days. An organisation with firms on several continents is rare, and can set the zone it runs its books in. The country's zone with no setting was rejected: a tenant with no firm yet, or a firm registered in one country and run from another, would have no way to correct it.

### Consequences

* Good: one day per tenant, right on summer time changes; no setup for the usual tenant; two queries per tick.
* Good: archiving the first firm moves an unset tenant to the next firm's country, which is what it now issues from.
* Bad: a tenant whose firms are in different zones has all its daily work in one of them; the operator sets the one that matters.
* Bad: the zone is set from the console only; a settings screen can follow when tenants manage themselves.
