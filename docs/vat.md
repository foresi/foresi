# VAT

The VAT module (`src/Vat`, id `vat`, package `foresi/vat`, scope `vat`) shows a firm that is a VAT payer its **VAT periods**: which month or quarter it files, when the return and the payment are due, and when a refund is expected. It counts the **VAT of the documents** in each period, and keeps **where the firm pays its VAT**. Later steps add the flow into the Cash flow forecast, the matching of the bank payments, and the VAT of recurring invoices and plans.

Every answer is an **estimate**, not the base of a return. It says so (`estimate: true`), names the rules nobody has confirmed from a primary source yet (`unverified`) and what the amounts do not count yet (`known_gaps`). A past period that is not paid is never called overdue.

The module is optional. It requires Party, Identity, Ui and the core modules. Cash flow, Invoicing and Purchasing are optional (ADR 0033). The rules of a country come from a country module through `Foresi\Vat\Contract\VatCountryRules`; the Country module has the Czech ones.

## Who works with it

The VAT is the firm's, not a person's: the owners and the members read and change it, on the screens and with a token that holds `vat:read` or `vat:write`. A guest never sees it.

## Periods

The firm's VAT status over time is kept on the firm, under *Settings → Firm → VAT*: each status holds from its day until the next one, and a payer has a period length, a month or a quarter. The module reads it through `Foresi\Party\Contract\FirmVatStatuses`.

- The periods begin on the first day the firm is a payer with a stated date. A period is the calendar month or quarter of its stretch, **clipped to the days the firm is a payer**: a payer from 12 November 2026 has the period `2026-11` from 2026-11-12 to 2026-11-30.
- Its due date and refund date are those of the whole period, so the clipped November is due as every November is.
- When the firm stops being a payer, the last period ends the day before. When it becomes one again, new periods begin.
- Nothing is computed before a stated date. A firm whose only payer status has no date (a firm set up before the statuses had dates) gets no periods and the reason `payer_date_missing`: state the day on the firm's VAT tab. A firm that is no payer gets `not_a_payer`, and a firm in a country without rules `country_not_supported`.
- The list runs to the period that holds the twelfth month after this one, or to the month asked for, at most 24 months ahead.

## Amounts

Each period has its output VAT, its deductible input VAT and the result, the output minus the input, in CZK: positive to pay, negative to get back. The documents come from Invoicing and Purchasing through `Foresi\Vat\Contract\VatDocumentSource`; without one of them its source is listed in `unavailable_sources` and its documents count nothing.

- **Issued documents** (`invoicing.issued_vat`): the tax documents the accountant gets, the invoices, the tax documents on payment and the corrective tax documents not in the trash. Each counts on its tax point, with the VAT of its rate summaries converted at its own exchange rate. An advance invoice is no tax document and adds nothing; the tax document on its payment counts. Whether a document is paid does not matter.
- **Received documents** (`purchasing.received_vat`): the recorded documents not in the trash, in their deduction month. Domestic VAT the supplier charged is input. In the self-assessed treatments (domestic reverse charge, a service from the EU or from outside it) the VAT is both output and input. Non-deductible VAT, a document without VAT and a draft give nothing. A credit note lowers the input. A document whose VAT has no CZK amount counts zero, and the period warns `missing_czk_vat` and names it in `missing_czk`.
- A document counts in the period whose month or quarter holds the day it counts on, so a quarterly filer finds a received document's deduction month in its quarter.
- A document counts only when the firm is a payer on its tax point. The deduction of VAT on supplies received before the registration is not supported (`known_gaps`: `deduction_before_registration`): a payer from 12 November 2026 deducts nothing for a document with the tax point 5 November, even in its deduction month November.
- `breakdown` keeps the documents, the schedules and the plans apart, and `steps` shows the output, the input and the result with the rule and the sources of each. Recurring invoices and plans are not counted yet (`schedules_not_counted`, `plans_not_counted`).

`GET /api/v1/vat/periods/{key}` lists the documents of one period. A token that may not read the invoices or the received documents sees the totals only, and the source in `restricted_sources`.

## Czech rules

| Rule | Source |
| --- | --- |
| A period is a calendar month, or a calendar quarter for a payer who may choose it. | VAT Act (Act 235/2004 Coll.) sections 99 and 99a |
| The return and the payment are due by the 25th day after the period ends; a weekend or a public holiday moves it to the next working day. November 2026 is due on Monday 28 December 2026. | VAT Act section 101, Tax Code (Act 280/2009 Coll.) section 33 (4) |
| A refund of an excess deduction is expected 30 days after the due date, moved to a working day. **Unverified** (`refund_timing`). | Tax Code section 155b (3) |
| The VAT is paid to the account of the payer's tax office, `705-<number of the office>/0710`, with the digits of the VAT ID as the variable symbol. The office's number is not kept in Foresi: enter your own account. | Financial Administration |
| A quarter is not allowed in the calendar year of registration nor in the year after. It is a warning (`quarter_not_allowed`): the length is kept. | VAT Act section 99a (3) |

The working days and the public holidays are shared with the Taxes and contributions deadlines (`Foresi\Country\CzechRepublic\Calendar\CzechWorkingDays`).

The open points, each listed in `unverified` until an accountant confirms them: `refund_timing`, `refund_account`, `office_account_2026`, `late_deduction_limit`, `quarter_limit_2026` and `vat_by_tax_point_cash_basis`.

## Payment details

The account of the tax office and the variable symbol match the bank payments to the periods. An empty variable symbol takes the one the country suggests. `GET /api/v1/vat/settings` shows the suggestion with its source.

## API and MCP

See [VAT in the REST API](api.md#vat) and the tools `foresi_vat_periods`, `foresi_get_vat_period` and `foresi_set_vat_settings` in [mcp.md](mcp.md).
