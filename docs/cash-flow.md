# Cash flow

The Cash flow module (`src/CashFlow`, id `cash-flow`, scope `cashflow`) tells a firm how much money it will have: by ISO weeks until the end of next month, then by calendar months, 12 months in all. It owns the planned items, the bank balance checkpoints, the expected days of documents and the minimum balance of a firm. It computes the forecast on read and stores none of it.

The module is optional. It requires Party, Identity, the exchange rates and the core modules, and no module that feeds it. Other modules plug in through its contract (ADR 0033), so Cash flow knows none of them by name. Invoicing, Purchasing and Banking do so when Cash flow is installed; see [Sources](#sources).

Everything is available through the [REST API](api.md#cash-flow) and the [MCP server](mcp.md#tools), and owners and members see it on the [Cash flow page](#the-cash-flow-page).

## The Cash flow page

**Money → Cash flow** (`/cash-flow`) reads `Forecasts::of()`, the same forecast as the API, for owners and members.

- **Header**: **Enter balance** opens a dialog (account, day, the balance at its end); **⋯ → Minimum balance** opens a dialog with Save and, once set, Clear.
- **Alert**: when the forecast is stale or incomplete, a warning names each account without a balance, a balance older than 7 days, a source that failed and a currency without a rate, with its own Enter balance button.
- **Figures**: the balance today with where it comes from (the entered balance and its day, and whether bank movements were added), the lowest point and its day, the minimum balance with "Below on" the first day under it or "Never below", and the balance at the end of the forecast.
- **3 months or 12 months**: tabs pick one granularity for the chart and the table alike: thirteen ISO weeks from this one (the first starts today), or twelve calendar months from today (no week in front of them; the first month starts today). In the weeks view the lowest point, the first day under the minimum and the end are those of the thirteen weeks. A link to a week's cell carries its key (`2026-W48`), so the sheet reads the same view.
- **+ Filter**: the filter builder of the list pages, with one condition, **Source** (Open documents, Recurring invoices, Planned items, Tax, insurance and VAT estimate: the certainties `document`, `schedule`, `plan` and `estimate`; is, is not, is any of, is none of). It sits at the end of the row of the 3 months and 12 months tabs. The chart, the table, the figures and the sheet count only the flows it keeps; the balance today and the bank movements in it are never filtered. The filter travels in the address (`f`), through the 3 months or 12 months tabs and the links to a cell's items. A filter that keeps no flow shows the empty state of the table with Clear the filter. The page stores no view of it.
- **Chart** (`Ui:BalanceChart`): money in (green) and out (sand) of each period as two columns, the closing balance as a line over them, and the minimum balance dashed. It is a server-rendered SVG with the table under it as its accessible equivalent; on a phone it scrolls sideways. Hovering a period shows its money in, out and closing balance in a tooltip.
- **By period**: the periods of the view, with the opening balance, money in and money out by category, and the closing balance, red when it is below the minimum balance. A cell with money opens its items in the side sheet: the label, a badge for how sure each is (Document, Schedule, Plan, or Estimate in its own outlined badge, so an estimate never reads as a document or a bank movement), where it comes from when that says more (invoice, recurring invoice, received document), the day it is counted on, its flags and the amount in its own currency.
- **Empty states**: before the first-run setup; a firm without a bank account; and a firm with no balance and nothing expected, which asks for the balance first.

## Planned items

A planned item is money a firm expects to receive or pay that no document carries yet: rent, software, insurance and tax advances, a client not invoiced yet, a one-off purchase. It is kept outside the books and generates nothing there.

- **Direction** `in` or `out`, a **name**, a **category** and a positive **amount** in CZK, EUR or USD.
- A **day** (`on`). Without `repeat` the item falls once on that day. With `repeat` (`day`, `week`, `month` or `year`) it recurs from that day every `every` units, on the last day of the month with `last_day_of_month`, until `ends_on` or after `ends_after` occurrences. The schedule is the shared `Recurrence` of recurring invoices.
- An optional **counterparty**, a contact of the address book.
- **Included**: an item that is not included stays, but the forecast leaves it out, for a "what if this client leaves".
- **Skipped occurrences**: single days the item will not happen on, such as an advance not due this month. Only a day the item falls on can be skipped.
- **End**: a recurring item can be ended on a day; nothing falls after it. A one-off item is changed or deleted instead.
- A **match rule**: the counterparty's account, the variable symbol and a tolerance of the amount. A bank movement that meets it replaces the planned occurrence; see [What replaces a plan](#what-replaces-a-plan).
- Its **VAT**: `vat_mode` `none` (the default), `included` (the amount holds the VAT) or `excluded` (the VAT comes on top) or `reverse_charge` (the firm as the recipient self-assesses the VAT), with `vat_rate`, a percent from 0 to 100 with at most two decimals. The VAT counts only on the days the firm is a VAT payer (`FirmVatStatuses`); on any other day the amount moves as stated. On a payer's day an `included` item moves its amount, of which `amount × rate / (100 + rate)` is VAT; an `excluded` item moves `amount + amount × rate / 100`, and a bank movement fulfils it at that amount. A `reverse_charge` item moves its amount as stated, its net, and self-assesses `amount × rate / 100` of VAT the amount does not hold: a domestic reverse charge, or a service from the EU or from outside it, such as a foreign software subscription of 32 USD a month, which stays 32 USD in the cash flow and counts 6.72 USD of VAT as both output and input in the VAT period, so it adds nothing to pay. It is for money out only (`vat_mode_reverse_charge_in`), and the income and tax estimate counts its amount as net. The VAT is rounded half up to the minor unit once. The item answers `net` and `vat` of one occurrence as stated, and `vat_missing` while the firm is a payer and the item states no VAT, but for the categories that carry none (`vat`, `taxes`, `insurance`, `loans`, `owner_draws`). The planned-item lists mark such an item *VAT not stated*. Every item from before the VAT could be stated has `none`, so no forecast changed.

A planned occurrence that has passed is not overdue: it was a plan, and what really happened is in the balance.

Changes record the events `cash-flow.planned_item.created`, `changed`, `occurrence_skipped`, `ended` and `deleted`, version 1. Their facts are the ids, the direction, and the amount with its currency or the day; the name and the counterparty are no facts.

## Categories

`revenue`, `purchases`, `rent`, `software`, `payroll` (salaries and contractors), `taxes`, `insurance` (social and health), `vat`, `loans`, `owner_draws` and `other`. A document takes the category of its source: issued invoices are revenue, received documents purchases. Configurable categories come later.

## The balance

A **checkpoint** is the balance of a firm bank account at the end of a day, in the currency of the account, entered by hand or through the API. Entering the same day again corrects it; a day after today is refused. The latest checkpoint of each account on or before today is where the forecast starts.

A `BalanceSource` of another module may add what was booked after the checkpoint. Banking (`banking.movements`) adds the movements of the account booked after the checkpoint's day up to today, and the balance is then as of the last of them. It leaves out possible duplicates and movements in another currency than the account's, and does not answer for an account with no movement at all. Without one the checkpoint is the balance. The forecast says the balance is **stale** when the oldest balance of the firm's accounts is older than 7 days, or when there is none. An active account without a checkpoint has no balance and makes the forecast incomplete.

## The forecast

`GET /api/v1/cash-flow/forecast` or the MCP tool `foresi_cash_flow_forecast`.

- **Today** is the instance's today (`InstanceClock::today()`), so the hour after midnight in Prague is the new Prague day, though UTC is still on the day before.
- **Periods**: ISO weeks (Monday to Sunday) from `from` (today by default) while a week starts on or before `weeks_until` (the end of next month by default), then calendar months up to the end of the month `months - 1` after the month of `from` (`months` is 12 by default, 1 to 24). The first week starts on `from` and the first month on the day after the last week, so either may be shorter.
- **Lines**: every source is asked for the flows from today to the end of the horizon. A flow still open before today is counted on the first day and flagged `overdue`, unless its source flagged it `awaited`: its date has passed, but it is not late, such as a tax whose final amount only the return knows. It is counted on the first day too, with the overdue flows, and never flagged `overdue`. On the page it reads Not yet paid (money out) or Not yet received (money in) in a neutral badge, never red. A document (certainty `document`) with an expected day is counted on that day instead of its due date and flagged `expected_date`. A flow after the horizon is left out. Flows between today and a later `from` are counted in the opening of the first period.
- **Currency**: every amount is converted to CZK at today's ČNB rate (the exchange-rate module returns today's rate for a future day). Each line keeps its own amount and currency. A currency without a rate today is listed in `unavailable_rates` and its lines are left out.
- **Each period** has `opening`, `inflows` and `outflows` with a `total`, the amounts `by_category` and `by_certainty`, `closing`, and its `items`.
- **Certainty**: `certainty` (REST: `document,plan`; MCP: a list) keeps only the flows of the certainties named, and the answer echoes them in `certainty`. The balances of the periods, `lowest` and `shortfall_on` are those of the flows kept; the balance today is never filtered. An unknown certainty is refused with `invalid_certainty`.
- **Lowest point**: the lowest balance at the end of a day within the horizon (`lowest`), or the opening when nothing falls below it.
- **Shortfall**: with a minimum balance set for the firm, `shortfall_on` is the first day the projected balance falls below it. It is a field of the answer, not a notification.
- **Actuals**: a planned occurrence that a document or a bank movement already stands for is left out; see [What replaces a plan](#what-replaces-a-plan).
- **Restricted sources**: a source whose documents the caller may not read, such as an API token with `cashflow:read` and no `invoices:read`, still counts in the balance and in `inflows` and `outflows`, but its items are left out of the period. The period lists them as `hidden_items` instead, a total per source, direction and category with the count of items, and the forecast names such a source in `restricted_sources`. People signed in on the screens read every source.
- **Isolation**: a source or a balance source that throws is logged and listed in `unavailable_sources`; nothing it gave is counted, and the forecast is `incomplete`. So is a forecast with an account without a balance or a currency without a rate.

## Sources

| Key | Module | What it gives | Details need |
| --- | --- | --- | --- |
| `cash-flow.planned_items` | Cash flow | The planned items that are included, certainty `plan`. | `cashflow:read` |
| `invoicing.invoices` | Invoicing | Unpaid invoices and advance invoices: what remains after corrections, payments and, for a final invoice, the advances; on the due date; category `revenue`, certainty `document`. | `invoices:read` |
| `invoicing.recurring_invoices` | Invoicing | The occurrences of active recurring invoices not issued yet: the payable amount on the issue day plus the template's due days, certainty `schedule`. | `invoices:read` |
| `purchasing.received_documents` | Purchasing | Recorded received documents not fully paid: what remains, on the due date, or on the received date (else the issue date) with the flag `no_due_date`. A credit note comes in. Category `purchases`. | `received_documents:read` |
| `taxes-and-contributions.estimate` | Taxes and contributions | The advances and settlements of the owner not paid yet, on their due days, certainty `estimate`, category `taxes` or `insurance` (see [Payments](taxes-and-contributions.md#payments)). | the owner with `taxes:read` |
| `banking.movements` | Banking | The balance after a checkpoint (see [The balance](#the-balance)) and the bank movements as actuals. | — |

Banking records the event `banking.movement.recorded` (version 1) for every movement it records, with its firm, account, direction, amount, currency, booking day, state and variable symbol. Owners and team members read it in the event feed and through webhooks.

## What replaces a plan

A plan stands until the facts arrive. Each source with actuals (`ActualFlowSource`) tells the forecast what has already happened: issued invoices and advances by issue day and customer, received documents by issue day and supplier, and bank movements by booking day with the counter account and the variable symbol. The forecast asks for them once, from the start of the earliest period it needs to the end of the horizon.

The **period** of a planned occurrence is the cycle of its item around it: the calendar unit of its schedule that holds the day, with the units before it when the item repeats every few of them. A monthly item has its calendar month, one every three months the three calendar months ending with the occurrence's, a weekly one its ISO week, a yearly one its calendar year. A one-off item has the days from its creation to its day.

An occurrence is left out when, in its period and in its direction:

- **a document of its counterparty** is there, for a recurring item with a counterparty (C13). The rent of a month that the landlord has invoiced is in the forecast once, as the received document;
- **a bank movement meets its match rule** (C16): the counterparty account it names (compared without spaces and without the zeros a Czech number may be padded with), the variable symbol it names (without leading zeros), the same currency, and the amount within the tolerance (exact without one).

An actual source that fails is listed in `unavailable_sources`, and then nothing is left out because of it.

## Pairing documents with planned occurrences

A document that stands for a planned occurrence can be paired with it, so the occurrence is not counted twice and the page of the item shows what was invoiced for it. The pairing is stored (`cash_flow_document_pairing`), one row per document, with the occurrence it points at and the occurrences it may belong to (`candidates`).

**Candidates.** The recurring, included planned items of the document's direction and counterparty that have an occurrence whose period (see [What replaces a plan](#what-replaces-a-plan)) holds the document's day. A skipped occurrence and any after the item's end are not candidates. Periods are counted in the instance's zone. A document has the day of its tax point, else its issue date.

**States.**

| State | Meaning |
| --- | --- |
| `auto` | Cash flow paired it: exactly one candidate, no other document of the same counterparty and direction in that occurrence's period (a declined one does not count), the currency of the item and an amount within the item's tolerance (exact without one) of what the forecast expects, VAT on top included. |
| `proposed` | There is at least one candidate, but the rules above do not all hold, such as two documents in one period or another amount. A person decides. With one candidate the row also points at it. |
| `confirmed` | A person paired it, with a candidate or with any other occurrence the item falls on. |
| `declined` | A person said it belongs to none; Cash flow never pairs it on its own again. |

A second document arriving in the period of an `auto` occurrence turns the first one into `proposed`; when one of them is forgotten, the other can become `auto` again. A document with no candidate has no row. Cash flow never changes a `confirmed` or `declined` row; only a person does, over the API, MCP or the screens. A row of a person lists the occurrence chosen among its `candidates`, also when it was not offered.

**Fulfilment.** An occurrence with an `auto` or `confirmed` pairing is left out of the forecast, whatever the item's counterparty and match rule. A `proposed` or `declined` pairing leaves it in, until the document counts as an actual (see [What replaces a plan](#what-replaces-a-plan)).

**What changes a pairing.**

- A recurring planned item that is **created**, **changed** (counterparty, direction, amount, VAT, tolerance, schedule, included) or **ended**: the documents of its counterparty are looked at again, 13 months back and 13 months ahead. An occurrence that is **skipped**: the documents of its period are looked at again.
- A planned item that is **deleted** is refused with `has_pairings` while an `auto` or `confirmed` pairing points at it. Proposals that list it lose it as a candidate; one left with none is removed, one left with one points at it.
- A document that is recorded, changed or restored from the trash is considered at once, in the same transaction. When it is trashed or no longer complete, its source stops offering it, and it is forgotten: its row goes and the other documents of the occurrence are looked at again.

Each change of a row records the event `cash-flow.document_pairing.changed` (version 1) with the document (`subject_type`, `subject_id`), the `state` (null when the row was removed), the `planned_item_id`, the `occurrence_on` and the `previous_planned_item_id`. Owners and team members read it in the event feed and through webhooks.

**Operations.** `GET /api/v1/cash-flow/pairings`, `POST /api/v1/cash-flow/pairings` and `DELETE /api/v1/cash-flow/pairings/{subjectType}/{subjectId}`, and the tools `foresi_list_pairings`, `foresi_pair_document` and `foresi_unpair_document`; see [the API](api.md#cash-flow) and [MCP](mcp.md#tools).

**Backfill.** `bin/console cashflow:pairing:backfill` looks at every document dated in the last 13 months once, as if it had just been recorded, and prints the number of pairings per state. It leaves what a person decided and changes nothing on a second run. Run it once after installing Cash flow next to existing documents, or after adding planned items for suppliers that are already invoiced.

**Margin.** Margin has its own backfill for documents recorded before it was installed: `bin/console margin:recalculate` (see [Margin](margin.md#documents-recorded-before-margin)).

**Plugging in documents.** A module whose documents may stand for planned money implements `Foresi\CashFlow\Contract\PairableDocumentSource` in its `Integration/CashFlow/` directory (tag `app.cash_flow_pairable_documents`, added by autoconfiguration): `one()`, `many()` (several of one type in one lookup), `of()` (a counterparty's documents in a period) and `since()` give `PairableDocument`s: the subject, the counterparty id, the direction, a positive amount in the document's currency and the day. A trashed document and a draft are not offered. It then asks `Foresi\CashFlow\Contract\DocumentPairer::consider()` when a document is recorded or changed and `forget()` when it goes. A module that shows pairings on its own screens uses `Foresi\CashFlow\Contract\DocumentPairings` (the state of a document, the items and days to choose from, pair and unpair, the documents that wait for a decision). `PlannedPairings` answers which occurrence a document is paired with.

**On the screens.** The page of a received document shows a **Plan** panel (see [Received documents](purchasing.md#the-plan-panel)), and the page of a planned item lists the documents paired with each occurrence, with a badge for the automatic ones.

## Plugging in a source

A module that expects money implements `Foresi\CashFlow\Contract\CashFlowSource` in its `Integration/CashFlow/` directory, declares `cash-flow` in `extra.foresi.optional` of its manifest (ADR 0033), and is collected by the tag `app.cash_flow_source`:

```php
interface CashFlowSource
{
    public function key(): string; // such as "invoicing.invoices"

    /** @return iterable<ExpectedFlow> */
    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable;

    /** Whether the caller may read the documents behind the flows. */
    public function mayReadDetails(): bool;
}
```

An `ExpectedFlow` carries the source key, its subject (type, id and API URL), the direction, a positive `Money`, the expected day, the category, the certainty (`document`, `schedule`, `plan` or `estimate`), a label, flags (`estimated`, `no_due_date`, `awaited` on a flow dated before today that is not late) and an optional counterparty id. A source returns open flows only, overdue ones included. When `mayReadDetails()` is false, the forecast shows only the totals of its flows, never a document.

A module whose documents or movements stand for planned money also implements `Foresi\CashFlow\Contract\ActualFlowSource` (tag `app.cash_flow_actual_source`): `actuals(firmId, from, to)` returns `ActualFlow`s with the source key, the subject, the direction, the amount, the day, and the counterparty id (a document) or the counter account and variable symbol (a bank movement).

A balance source implements `Foresi\CashFlow\Contract\BalanceSource` (tag `app.cash_flow_balance_source`): given the latest `BalanceCheckpoint` of an account and today, it answers an `AccountBalance` with the amount and the last day its knowledge reaches, or null when it does not know the account. The first source that answers for an account wins.

The planned items of Cash flow are a source of their own, `cash-flow.planned_items`.

## Reading the forecast from a module

A module that needs the flows of the forecast, such as Taxes and contributions reading the revenue expected by the end of the year, asks `Foresi\CashFlow\Contract\ForecastReader` (ADR 0033: Cash flow owns the contract, so the module declares `cash-flow` optional):

```php
interface ForecastReader
{
    public function read(ForecastQuery $query): ForecastRead;
}
```

- `ForecastQuery` has `from` (today or later), `to` (within 24 months of today; there is no twelve-month cap), the keys of the sources to leave out (`excludeSources`), the categories to leave out (`excludeCategories`) and the certainties to keep (`certainties`, all when null).
- A source left out is **never called**. A module that is itself a cash-flow source leaves its own key out, so reading the forecast never asks it again. A source whose flows fall only in some categories says so with `Foresi\CashFlow\Contract\CategorisedSource`, and a read that leaves out all of them never calls it either: the VAT periods (`vat`) and the tax estimate (`taxes`, `insurance`) both read the forecast from inside their sources, and neither asks the other.
- The sources are asked by `ForecastFlows`, the collector `Forecasts` uses too: the same expected days, the same overdue rule and the same planned-item fulfilment, so no flow is counted twice.
- `ForecastRead` holds the `flows`, the `unavailableSources`, the `restrictedSources` and `incomplete` (a source failed or a currency had no rate). A `ReadFlow` has the source key, the subject (null for a restricted source, whose amounts still come back), the direction, the amount in its own currency and in CZK, the day it is counted on (`on`: the expected day, or the first day of the read for an overdue flow), the category, the certainty, the flags, the label (null like the subject) and `vat`, the VAT the amount holds in its own currency, null when none is stated; `vatCzk()` gives it in CZK in the ratio of the amount. Flows between today and a later `from` are left out. It computes no balance.
- Only the owners and members of the organisation read it; a guest is refused with `forbidden`.
