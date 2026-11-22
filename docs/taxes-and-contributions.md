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
- the children claimed: an optional name, the birth date, a severe disability card (ZTP/P) and the months claimed, 0 to 12 (12 by default). The order (1, 2, or 3 for the third and further) follows the birth dates, the eldest first; the owner fixes another only when the parents agreed on one, and a child is listed by birth date;
- the spouse: the months claimed, a severe disability card, and whether their own income stays at most 68,000 CZK;
- the deductions by type (`gifts`, `housing_loan_interest`, `pension_products`, `life_insurance`, `long_term_investment`, `union_fees`, `exam_fees`);
- the other partial bases (employment and the tax withheld, rental, other) and the last known tax;
- the previous year: the social and health bases, the day the overviews were filed and the months of activity (1 to 12, `previous_year.activity_months`);
- the return route (`paper`, `electronic` by default, or `advisor`), the start of the activity and an average wage that overrides the published one.

Setting a year replaces it as a whole: a field left out is cleared.

### Authority accounts

For each authority (`social`, `health`, `sickness`, `income_tax`, `flat_rate`), the account and the variable symbol the owner pays to. They will match the payments of the advances to the estimate.

## Actual amounts

The income received and the costs paid, on a cash basis, as the estimate of "to date" reads them. Invoicing gives the payments of issued invoices and advances, Purchasing those of recorded received documents; each implements `Foresi\TaxesAndContributions\Contract\ActualAmountSource` in its own `Integration/TaxesAndContributions` directory.

- A payment counts on the day it was paid, in the instance's time zone.
- A part payment splits into net and VAT in the ratio of its document, with the corrections of an issued invoice, such as a credit note, taken in. Net and VAT always add up to what was paid; a minor unit left over goes to the net.
- Of a received document only domestic VAT the supplier charged leaves the net. Self-assessed VAT was never paid to the supplier, and non-deductible VAT stays a cost.
- A refund to a customer, and the money a supplier's credit note brings back, count negative on their own day.
- A paid advance counts when it is paid. Its final invoice deducts it and asks only for the rest, so the money counts once.
- A cancelled payment does not count, and neither does a settlement written off, which was never received.
- A payment in another currency is converted to CZK at the ČNB rate of its day. This is flagged `fx_payment_day` in `unverified`, an open point for an accountant. A currency without a rate on that day is listed in `missing_rates`, and its payments are left out.
- `counted` is what the records count: the gross before the day the owner became a VAT payer (the status `vat_payer`), and the net from that day on. This is flagged `vat_cash_basis` in `unverified`.
- Purchasing cannot mark a cost as not tax deductible, or an asset over 80,000 CZK, yet: `known_gaps` lists `non_deductible_costs` and `assets`.
- A source that is not installed or failed is listed in `unavailable_sources`, so a missing module is never read as a year without income. A source whose documents the caller may not read (a token without `invoices:read` or `received_documents:read`) is listed in `restricted_sources` and counts in the sums only.

## Year end

The year end is "to date" plus the Cash flow forecast from today to 31 December, computed over every month of the year. It is always shown apart from "to date", never mixed into it. Cash flow fills `Foresi\TaxesAndContributions\Contract\ForecastAmountSource` through this module's `Integration/CashFlow` directory, which reads the forecast through `Foresi\CashFlow\Contract\ForecastReader`.

- The read never calls this module's own cash-flow source, `taxes-and-contributions.estimate`. It leaves out the categories `taxes`, `insurance`, `vat`, `loans` and `owner_draws`, and flows of the certainty `estimate`.
- It starts today. What was paid by today counts to date. An open document counts only what is still open on it, so nothing counts twice. A flow overdue before today counts today.
- An open invoice or received document splits into net and VAT in its own ratio, which `ActualAmountSource::sharesOf()` gives. It counts gross before the `vat_payer` day of its expected day, and net from that day on.
- A recurring invoice or a planned item counts as stated, with no VAT split, flagged `forecast.plan_no_vat_split`. A document whose records the caller may not read counts gross, flagged `forecast.no_vat_split_restricted`. A document without a known ratio counts gross too, flagged `forecast.no_vat_split_unknown`.
- `forecast_by_certainty` splits the forecast part into `document`, `schedule` and `plan`, each with its income and costs: gross, net, VAT and counted. The forecast's `incomplete` and `unavailable_sources` are passed on.
- `accruals` spreads the levies of the year end over its months by each month's share of the profit. A month of low profit, or of none, carries its smallest advances. The months add up to the levies of the year.
- Without Cash flow, `year_end` is null with the reason `cash_flow_not_installed`. A past year has no forecast, so its year end is what was paid.

## The flat-rate tax

A month whose `flat_rate_band` on its first day is `1`, `2` or `3`, with an activity, is in the Czech flat-rate tax. The result has it apart, in `flat_rate`: each month with its band and the band's amount split into `income_tax`, `social` and `health`, and the parts together. It is the same for every expense method; each method's totals add it as `flat_rate` to the levies and the take-home, and its own `social`, `health` and `income_tax` are those of the other months.

- In 2026, band 1 is 9,984 CZK a month to June and 9,162 CZK from July, band 2 is 16,745 CZK and band 3 is 27,139 CZK. In 2027, band 1 is 9,661 CZK, flagged `flat_rate_2027_band_1` because some sources say 9,662 CZK; bands 2 and 3 stay. Every value is dated and has its source among the rules used. The surcharge of band 1 for not recording sales from 2027 is listed in `known_gaps` as `flat_rate_surcharge`.
- In a flat-rate month no credit, deduction or child benefit applies. A year whose every active month is in it has no ordinary levies: the social, health and income tax steps say the flat rate covers them, and a `flat_rate` step adds the months up. Voluntary sickness insurance is still paid apart.
- A year with months both in and out of it counts the ordinary levies over the other months, with the profit for their share of the active months, and flags `flat_rate_mixed_year`.
- Becoming a VAT payer ends the flat rate at the end of that tax year: that year warns `flat_rate_ends_vat_payer`, and a later year counts as ordinary while the band stays, with the warning `flat_rate_ended_vat_payer`, until a new band status starts. The estimate never changes a status; the owner does.
- An income of the year above the band's limit for any activity (1,000,000, 1,500,000 and 2,000,000 CZK) warns `flat_rate_over_income_limit`; it never refuses, as higher limits hold when most of the income comes from activities with 80 % or 60 % lump sums.

## Payments

Every estimate lists the payments of its year by due day (`payments`): what falls due, and whether the bank has paid it. Nothing of it is stored; it is computed from the profile each time.

- The Czech sole trader pays the social advance of a month by its last day, the voluntary sickness advance too, and the health advance by the 8th of the next month. An advance is the previous year's base divided by the months of that year with an activity at least for part of the month, main or secondary alike (Act 592/1992 § 8 (2), Act 589/1992 § 14 (2)), times the rate, rounded up, and at least the minimum valid in that month: in 2026 the social one is 5,720 CZK to June and 5,005 CZK from July. The months are the ones entered for the year; else the activity statuses of the previous year count them; else they are twelve, flagged `previous_activity_months`. A 5-month previous year with a health base of 300,001 CZK gives 60,001 a month, times 13.5 %: 8,101 CZK. With no base entered it is the minimum. A secondary activity pays a social advance only with a base from the year before. The new base applies to social from the month after the overview and to health from the overview month; the overview day is the one entered for the year, or else its deadline.
- The income tax advances follow the last known tax: none up to 30,000 CZK, two of 40 % on 15 June and 15 December up to 150,000 CZK (flagged `middle_advance_band`), four of a quarter on the 15th of March, June, September and December above it. Each is rounded up to hundreds and moves to the next working day.
- A flat-rate month pays its band's amount to the authority `flat_rate` by the 20th of the month, the first month of the regime by the 20th of the next one, on the next working day. It has no social, health or income tax advance and no settlement.
- Once the year end is known, the settlement follows in the next year: the income tax on the return deadline of the route (tax year 2026: paper 1 April 2027, electronic 3 May 2027, advisor 1 July 2027; flagged `electronic_deadline_payment_date` for the electronic route), and the social and health shortfall 8 days after the overviews, which are due one month after the return (flagged `overview_deadlines` until the day they were filed is entered). The settlement is the year's levy with the chosen expense method less the advances; when the advances paid more, an `overpayment` comes back. Next year shows its advances only.
- Each payment takes the match rule of its authority: the account and the variable symbol in the profile. A bank movement that meets it, from the day the payment could be paid, makes it `paid` and names the movement. A movement pays one payment: the one it is on time for with the same amount first, else the oldest debt. A payment unpaid after its due day, in the instance's zone, is `overdue`; one to come is `expected`. A payment of an authority without an account cannot be checked, and the estimate warns `no_match_rule`.
- `possible_duplicates` lists the active planned items of Cash flow in the categories `taxes` and `insurance`, which the estimate flows may count twice, and the estimate warns `possible_duplicates`. The module never ends or changes them; the owner does in Cash flow.

### In the cash flow

The payments not yet paid are the source `taxes-and-contributions.estimate` of Cash flow: estimate flows, flagged `estimated`, on their due days, the income tax and the flat-rate tax in the category `taxes` and social, health and sickness insurance in `insurance`, an overpayment coming in. They cover last year, this year and next year. An overdue payment stays in the forecast only when its authority has an account, so the bank could have shown it paid. Before an activity is entered there are none.

The flows come from the owner's personal profile, so only the owner with `taxes:read` reads them one by one; anyone else sees them in the category totals only. The year end inside the source reads the forecast without this source, so the forecast never asks it again.

## The profile page

**Money → Taxes and contributions** (`/taxes-and-contributions/profile`), for the owner only:

- one line of the statuses that hold today, such as "sole trader · main activity · VAT payer from Nov 12, 2026", with a change still to come after them. Statuses change a few times in a firm's life, so they do not take the page.
- **Statuses** in the page header opens a dialog (`/taxes-and-contributions/profile/statuses`) with the dated list per kind, each with the day it holds from and until. **Add a change** in the dialog's header loads the form in the same dialog, and saving comes back to the list. A status is removed from its row after a confirmation.
- the years, this one and the next and every year with inputs. A year's heading names the statuses that change in it, such as "from Nov 12: VAT payer". Each of its three sections opens its form in the side sheet.
- the five authorities; each opens its account in a small dialog.

## API and MCP

See [api.md](api.md#taxes-and-contributions) and [mcp.md](mcp.md#tools).

## Events

`taxes-and-contributions.profile.changed` (version 1) is recorded with every change. Its facts say only what changed: `what` (`status`, `year` or `authority`), the `kind` (of the status, or the authority), the `year` and `valid_from`. It carries no value, child, birth date or amount. Only the owner reads these events.
