# Taxes and contributions

The Taxes and contributions module (`src/TaxesAndContributions`, id `taxes-and-contributions`, scope `taxes`) will show the owner of a firm what they earn, what of it goes to taxes and contributions, and what they keep, both to date and to the year end. The first case is a Czech sole trader. It holds the **owner profile** the estimate is built from and reads the **actual amounts** paid; the estimate itself comes in the next steps.

The module is optional. It requires Party, Identity, the exchange rates, Ui and the core modules. Cash flow, Invoicing and Purchasing are optional: they feed it when they are installed (ADR 0033).

## Owner only

The profile holds personal data: children, their birth dates, the spouse and amounts. So only the **owner** role reads or changes it, on the screens and with a token alike. A member, even with a token of `taxes:read` or `read`, is refused with `403 owner_only`, and does not see the menu item. A guest never sees the tools.

## The owner profile

There is one profile per instance, the profile of the natural person who is the sole trader. It has three parts.

### Statuses

A status holds from its day (`valid_from`) until the next status of the same kind, so a change in the middle of the year is one more status. For example, `vat_payer` is `no` from 2026-01-01 and `yes` from 2026-11-12. Two statuses of one kind never start on the same day.

| Kind | Values |
| --- | --- |
| `legal_form` | `sole_trader` |
| `activity` | `none`, `secondary`, `main` |
| `vat_payer` | `yes`, `no` |
| `flat_rate_band` | `none`, `1`, `2`, `3` |
| `sickness_insurance` | `off`, `on` with `base`, the monthly base in CZK |
| `health_minimum_exempt` | `yes`, `no` |

Removing a status lets the one before it hold again.

### The inputs of a year

Each year has its own inputs, all amounts in CZK:

- the expense methods to compare (`actual`, `lump_80`, `lump_60`, `lump_40`, `lump_30`; `actual` and `lump_60` by default) and the one chosen;
- the children claimed: a label of the owner's choice, the birth date, the order (1, 2, or 3 for the third and further), a severe disability card (ZTP/P) and the months claimed, 0 to 12;
- the spouse: the months claimed, a severe disability card, and whether their own income stays at most 68,000 CZK;
- the deductions by type (`gifts`, `housing_loan_interest`, `pension_products`, `life_insurance`, `long_term_investment`, `union_fees`, `exam_fees`);
- the other partial bases (employment and the tax withheld, rental, other) and the last known tax;
- the previous year: the social and health bases and the day the overviews were filed;
- the return route (`paper`, `electronic` by default, or `advisor`), the start of the activity and an average wage that overrides the published one.

Setting a year replaces it as a whole: a field left out is cleared.

### Authority accounts

For each authority (`social`, `health`, `sickness`, `income_tax`), the account and the variable symbol the owner pays to. They will match the payments of the advances to the estimate.

## Actual amounts

The income received and the costs paid, on a cash basis, as the estimate of "to date" reads them. Invoicing gives the payments of issued invoices and advances, Purchasing those of recorded received documents; each implements `Foresi\TaxesAndContributions\Contract\ActualAmountSource` in its own `Integration/TaxesAndContributions` directory.

- A payment counts on the day it was paid, in the instance's time zone.
- A part payment splits into net and VAT in the ratio of its document, with the corrections of an issued invoice, such as a credit note, taken in. The net is rounded half up and the VAT takes the rest, so net and VAT always add up to what was paid.
- Of a received document only domestic VAT the supplier charged leaves the net. Self-assessed VAT was never paid to the supplier, and non-deductible VAT stays a cost.
- A refund to a customer, and the money a supplier's credit note brings back, count negative on their own day.
- A paid advance counts when it is paid. Its final invoice deducts it and asks only for the rest, so the money counts once.
- A cancelled payment does not count, and neither does a settlement written off, which was never received.
- A payment in another currency is converted to CZK at the ČNB rate of its day. This is flagged `fx_payment_day` in `unverified`, an open point for an accountant. A currency without a rate on that day is listed in `missing_rates`, and its payments are left out.
- `counted` is what the records count: the gross before the day the owner became a VAT payer (the status `vat_payer`), and the net from that day on. This is flagged `vat_cash_basis` in `unverified`.
- Purchasing cannot mark a cost as not tax deductible, or an asset over 80,000 CZK, yet: `known_gaps` lists `non_deductible_costs` and `assets`.
- A source that is not installed or failed is listed in `unavailable_sources`, so a missing module is never read as a year without income. A source whose documents the caller may not read (a token without `invoices:read` or `received_documents:read`) is listed in `restricted_sources` and counts in the sums only.

## The profile page

**Money → Taxes and contributions** (`/taxes-and-contributions/profile`), for the owner only:

- the statuses as a dated list per kind, each with the day it holds from and until, and **Add a change** in the page header, which opens a dialog. A status is removed from its row after a confirmation.
- the years, this one and the next and every year with inputs; a year opens its inputs as a form in the side sheet.
- the four authorities; each opens its account in a small dialog.

## API and MCP

See [api.md](api.md#taxes-and-contributions) and [mcp.md](mcp.md#tools).

## Events

`taxes-and-contributions.profile.changed` (version 1) is recorded with every change. Its facts say only what changed: `what` (`status`, `year` or `authority`), the `kind` (of the status, or the authority), the `year` and `valid_from`. It carries no value, child, birth date or amount. Only the owner reads these events.
