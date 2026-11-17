# Cash flow

The Cash flow module (`src/CashFlow`, id `cash-flow`, scope `cashflow`) tells a firm how much money it will have: by ISO weeks until the end of next month, then by calendar months, 12 months in all. It owns the planned items, the bank balance checkpoints, the expected days of documents and the minimum balance of a firm. It computes the forecast on read and stores none of it.

The module is optional. It requires Party, Identity, the exchange rates and the core modules, and no module that feeds it. Other modules plug in through its contract (ADR 0033), so Cash flow knows none of them by name.

Everything is available through the [REST API](api.md#cash-flow) and the [MCP server](mcp.md#tools). There are no screens yet.

## Planned items

A planned item is money a firm expects to receive or pay that no document carries yet: rent, software, insurance and tax advances, a client not invoiced yet, a one-off purchase. It is kept outside the books and generates nothing there.

- **Direction** `in` or `out`, a **name**, a **category** and a positive **amount** in CZK, EUR or USD.
- A **day** (`on`). Without `repeat` the item falls once on that day. With `repeat` (`day`, `week`, `month` or `year`) it recurs from that day every `every` units, on the last day of the month with `last_day_of_month`, until `ends_on` or after `ends_after` occurrences. The schedule is the shared `Recurrence` of recurring invoices.
- An optional **counterparty**, a contact of the address book.
- **Included**: an item that is not included stays, but the forecast leaves it out, for a "what if this client leaves".
- **Skipped occurrences**: single days the item will not happen on, such as an advance not due this month. Only a day the item falls on can be skipped.
- **End**: a recurring item can be ended on a day; nothing falls after it. A one-off item is changed or deleted instead.
- A **match rule**: the counterparty's account, the variable symbol and a tolerance of the amount. It is stored now; pairing bank movements with planned occurrences comes with the bank integration.

A planned occurrence that has passed is not overdue: it was a plan, and what really happened is in the balance.

Changes record the events `cash-flow.planned_item.created`, `changed`, `occurrence_skipped`, `ended` and `deleted`, version 1. Their facts are the ids, the direction, and the amount with its currency or the day; the name and the counterparty are no facts.

## Categories

`revenue`, `purchases`, `rent`, `software`, `payroll` (salaries and contractors), `taxes`, `insurance` (social and health), `vat`, `loans`, `owner_draws` and `other`. A document takes the category of its source: issued invoices are revenue, received documents purchases. Configurable categories come later.

## The balance

A **checkpoint** is the balance of a firm bank account at the end of a day, in the currency of the account, entered by hand or through the API. Entering the same day again corrects it; a day after today is refused. The latest checkpoint of each account on or before today is where the forecast starts.

A `BalanceSource` of another module, such as Banking with its movements, may add what was booked after the checkpoint. Without one the checkpoint is the balance. The forecast says the balance is **stale** when the oldest balance of the firm's accounts is older than 7 days, or when there is none. An active account without a checkpoint has no balance and makes the forecast incomplete.

## The forecast

`GET /api/v1/cash-flow/forecast?firm_id=...` or the MCP tool `foresi_cash_flow_forecast`.

- **Today** is the tenant's today (`TenantClock::today()`), so the hour after midnight in Prague is the new Prague day, though UTC is still on the day before.
- **Periods**: ISO weeks (Monday to Sunday) from `from` (today by default) while a week starts on or before `weeks_until` (the end of next month by default), then calendar months up to the end of the month `months - 1` after the month of `from` (`months` is 12 by default, 1 to 24). The first week starts on `from` and the first month on the day after the last week, so either may be shorter.
- **Lines**: every source is asked for the flows from today to the end of the horizon. A flow still open before today is counted on the first day and flagged `overdue`. A document (certainty `document`) with an expected day is counted on that day instead of its due date and flagged `expected_date`. A flow after the horizon is left out. Flows between today and a later `from` are counted in the opening of the first period.
- **Currency**: every amount is converted to CZK at today's ČNB rate (the exchange-rate module returns today's rate for a future day). Each line keeps its own amount and currency. A currency without a rate today is listed in `unavailable_rates` and its lines are left out.
- **Each period** has `opening`, `inflows` and `outflows` with a `total` and the amounts `by_category`, `closing`, and its `items`.
- **Lowest point**: the lowest balance at the end of a day within the horizon (`lowest`), or the opening when nothing falls below it.
- **Shortfall**: with a minimum balance set for the firm, `shortfall_on` is the first day the projected balance falls below it. It is a field of the answer, not a notification.
- **Isolation**: a source or a balance source that throws is logged and listed in `unavailable_sources`; nothing it gave is counted, and the forecast is `incomplete`. So is a forecast with an account without a balance or a currency without a rate.

## Plugging in a source

A module that expects money implements `App\CashFlow\Contract\CashFlowSource` in its `Integration/CashFlow/` directory, declares `cash-flow` in `extra.foresi.optional` of its manifest (ADR 0033), and is collected by the tag `app.cash_flow_source`:

```php
interface CashFlowSource
{
    public function key(): string; // such as "invoicing.invoices"

    /** @return iterable<ExpectedFlow> */
    public function flows(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $today): iterable;
}
```

An `ExpectedFlow` carries the source key, its subject (type, id and API URL), the direction, a positive `Money`, the expected day, the category, the certainty (`document`, `schedule`, `plan` or `estimate`), a label, flags (`estimated`, `no_due_date`) and an optional counterparty id. A source returns open flows only, overdue ones included, and answers only for what the caller may read.

A balance source implements `App\CashFlow\Contract\BalanceSource` (tag `app.cash_flow_balance_source`): given the latest `BalanceCheckpoint` of an account and today, it answers an `AccountBalance` with the amount and the last day its knowledge reaches, or null when it does not know the account. The first source that answers for an account wins.

The planned items of Cash flow are a source of their own, `cash-flow.planned_items`.
