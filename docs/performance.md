# Performance baseline

Foresi measures how fast its pages, its invoice PDF and its import are on a data set of a realistic size. The numbers are compared from one night to the next. Two kinds of check run, and neither is a required check of a pull request:

- **Queries a page**, on every pull request and push to main (job `Queries a page` of `.github/workflows/perf.yml`). This check counts the SQL queries of each page on a tiny data set and on ten times as much. A count is exact on any machine, so it is checked exactly. A page whose count grows with the data runs a query per record (an N+1 query). A page over its budget runs more queries than it did when the budget was set.
- **The timed baseline**, every night at 03:30 UTC and on demand (`workflow_dispatch`, job `Baseline`). It runs on PostgreSQL 17 with the large data set and measures page latency with [k6](https://grafana.com/docs/k6/latest/), for one person and for ten people at once. It also times the invoice PDF and the import. A broken budget makes the job red, and a scheduled run then opens or comments on one issue labelled `performance`. Nothing blocks a merge.

Wall time is never checked on pull requests. Hosted runners vary by 3 % to 17 % from run to run, which is more than the changes worth catching. The nightly summary compares each number with the previous successful night and marks a move of more than 20 % with ⚠, as a warning only.

## The data sets

`tests/Perf/seed.php` writes a data set through the application's own services, so every record is valid. It uses example.com names and fixed random seeds, so every run writes the same data. Time entries alone are written in bulk.

| Record | tiny (query counts) | small (a one-person firm) | large (a 20-person firm after 5 years) |
|---|---:|---:|---:|
| Contacts | 5 | 200 | 10,000 |
| Invoices issued, 1 to 10 lines | 10 | 500 | 20,000 |
| Payments | 9 | 450 | 18,000 |
| Received documents | 5 | 300 | 10,000 |
| Projects and tasks | 2 and 10 | 20 and 200 | 500 and 20,000 |
| Time entries | 20 | 2,000 | 100,000 |
| Deals and activities | 3 and 10 | 50 and 200 | 2,000 and 20,000 |

The owner signs in as `perf@example.com` with `perf-password-1`, or with `PERF_PASSWORD`.

## What is measured, and the budgets

| What | Where | Budget | Source of the budget |
|---|---|---|---|
| Queries of each page in `tests/Perf/pages.json` | every pull request, SQLite | the count measured when the budget was set, and no growth with ten times the data | as Drupal and GitLab do |
| Page p95, one person, large set | nightly | ≤ 800 ms | web.dev "good" time to first byte |
| p95 over all pages, ten people at once, large set | nightly | ≤ 1.8 s | web.dev "poor" time to first byte |
| Invoice PDF of 100 lines (the most an invoice has), median of 10 warm renders | nightly | ≤ 1.5 s | measured 0.78 s on an M1 Pro, with room for a slower runner |
| Import of 10,000 contacts: run and undo | nightly, PostgreSQL | ≥ 150 rows/s | the existing assertion of `PartyImportThroughputTest` |

The pages are the dashboard, the lists of contacts (also searched), invoices, received documents, projects, tasks and deals, one contact, invoice, project and task, the time week and report, cash flow, and the API lists of contacts, invoices and time entries.

When a change adds a query to a page on purpose, raise that page's budget in `tests/Perf/PageQueryCountTest.php` in the same pull request. `KNOWN_GROWTH` lists the pages known to run a query per record, with the most queries they may run on the larger set until that is fixed.

**Not checked:** wall time on pull requests, SQLite timings, browser metrics (Lighthouse, Web Vitals), and the 200,000-row import limit, which takes hours at today's speed and is measured on demand only.

## Running it

The query counts and the PDF timing need nothing but the test environment:

```bash
vendor/bin/phpunit --group perf tests/Perf
```

The whole baseline needs PostgreSQL 17, nginx, PHP-FPM and k6 (`brew install nginx k6`; Homebrew's `php` includes PHP-FPM). Give `bin/perf` an empty database. It seeds the database and serves Foresi as production does (`APP_ENV=prod`, OPcache, nginx and PHP-FPM with ten workers). Then it runs k6 and the PHPUnit groups `perf` and `throughput`, and writes `var/perf/results.json` and `var/perf/summary.md`:

```bash
createdb foresi_perf
DATABASE_URL="postgresql://$(whoami)@127.0.0.1:5432/foresi_perf?serverVersion=17&charset=utf8" bin/perf --size=small
```

- `--size=large` seeds the large set. That took 62 minutes on an M1 Pro under load, 25 of them for the invoices.
- `--skip-seed` measures a database seeded before and keeps `var/perf/seed.json`.
- `--only=pages` or `--only=phpunit` runs one half.
- `--previous=FILE` compares with an earlier `results.json`.

The import gets a database of its own next to the seeded one, `<name>_import`, because its test rebuilds the schema. Drop both when done:

```bash
dropdb foresi_perf
```

```bash
dropdb foresi_perf_import
```

The server runs in production mode, except that the owner of the seed signs in with a password alone (`APP_REQUIRE_2FA=false`). It uses the test secrets of `.env.test`. Never point `bin/perf` at a database with real data.

## First numbers

Measured on 2026-11-26 on a MacBook M1 Pro (8 cores, 32 GB), PHP 8.4.17, PostgreSQL 17.7 from Homebrew, large data set, one run. The Linux numbers of the perf workflow replace these as the baseline.

**Pages, one person:** p95 under 800 ms on every page but two.

| Page | median | p95 |
|---|---:|---:|
| dashboard | 388 ms | 446 ms |
| contacts, search, one contact | 79–117 ms | 140–218 ms |
| invoices | 468 ms | 608 ms |
| invoice, received documents | 75–106 ms | 97–127 ms |
| projects, project, tasks, task | 98–162 ms | 145–292 ms |
| time week, time report | 235–311 ms | 356–468 ms |
| **deals** | 2,561 ms | **3,166 ms** |
| **cash flow** | 2,767 ms | **4,284 ms** |
| API lists | 24–39 ms | 38–58 ms |

**Ten people at once:** median 234 ms, **p95 4.9 s** (budget 1.8 s), 10.6 requests a second; the deals board and cash flow make the tail.

**Invoice PDF:** 136 ms for 1 line, 205 ms for 10, 860 ms for 100 (budget 1.5 s).

**Import of 10,000 contacts:** create 169 rows/s, **update 15 rows/s** (budget 150), so undo was not reached. Measured under heavy load earlier the same day, create ran at 28 rows/s.

**Queries a page:** 120 to 213 on every HTML page, 9 or 10 on the API. Of those, 85 to 117 are the same lookup of the person's membership role. The deals board grows from 189 to 352 queries with ten times the data (`KNOWN_GROWTH`).

**Seed of the large set, in rows a second, measured under load:** contacts 20, invoices 14, payments 98, received documents 13, tasks 38, deals 56, activities 83, time entries 11,600 (written in bulk). Everything but the time entries goes through the application services. Payments pay what an invoice asks (`payable()`). The invoices are issued in date order over five years, and the seed adds the deal stages and activity types a schema made by Doctrine lacks.
