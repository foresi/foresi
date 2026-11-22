# VAT

The VAT module (`src/Vat`, id `vat`, package `foresi/vat`, scope `vat`) shows a firm that is a VAT payer its **VAT periods**: which month or quarter it files, when the return and the payment are due, and when a refund is expected. It counts the **VAT of the documents** and of the **recurring invoices** not issued yet in each period, puts what each period expects into the **Cash flow forecast**, finds the **bank payments** that settle it, and keeps **where the firm pays its VAT**. Later steps add the VAT of plans.

Every answer is an **estimate**, not the base of a return. It says so (`estimate: true`), names the rules nobody has confirmed from a primary source yet (`unverified`) and what the amounts do not count yet (`known_gaps`). A past period that is not paid is never called overdue.

The module is optional. It requires Party, Identity, Exchange rates, Ui and the core modules. Cash flow, Invoicing and Purchasing are optional (ADR 0033). The rules of a country come from a country module through `Foresi\Vat\Contract\VatCountryRules`; the Country module has the Czech ones.

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
- **Recurring invoices** (`invoicing.recurring_vat`, through `Foresi\Vat\Contract\VatScheduleSource`): each occurrence of an active template that is not issued yet, the same ones the Cash flow forecast counts, adds output VAT in the period of the tax point its invoice would get when issued that day. The VAT is that of the template's lines, priced as an issue prices them. A paused or ended template adds nothing, and a line in reverse charge adds no output. An issued occurrence is an invoice and counts once, as a document: the template's next date has moved past it. A template in another currency is converted at today's rate of the Czech National Bank, and the period warns `fx_today`; a currency without a rate today counts zero and warns `missing_czk_vat`.
- `breakdown` keeps the documents, the schedules and the plans apart, and `steps` shows the output, the input and the result with the rule and the sources of each. Plans are not counted yet (`plans_not_counted`).

`GET /api/v1/vat/periods/{key}` lists the documents and the recurring invoices (`schedules`) of one period. A token that may not read the invoices or the received documents sees the totals only, and the source in `restricted_sources`.

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

## What a period expects, and when it is settled

A period **expects** its result, or the amount of the return when a person entered it (`PUT /api/v1/vat/periods/{key}/return-amount`); the result stays in the answer next to it. A period is in one of four states:

- `settled`: a bank movement pays it, or a person marked it settled (`POST …/settle`, with a note, for a period paid from an account the bank does not show);
- otherwise `not_yet_paid`, once its due date has passed, or for a refund its expected refund date. A period that expects nothing is never unpaid;
- otherwise `return_entered`, when the return amount is entered;
- otherwise `estimated`.

A past period that is not paid is **not yet paid, never overdue**: only the return knows its final amount, and "today" is the day in the instance's time zone.

What the movements paid (`paid`) and the **difference** to what the period expects are information: a payment of 1,500 for an estimate of 1,890 settles the period, and the difference of −390 is shown, nothing more.

## Matching the bank payments

With the Cash flow module and the office account set, the module reads the bank movements to and from that account, from the first period to today, through Cash flow's movement finder. **The amount never decides a match.**

- A movement **to** the office account with the variable symbol pays the oldest unsettled period to pay that ended before the movement and whose window is still open: the window closes on the due date of the next period. A late November payment in January still pays November. When no period fits, it adds to the latest period it already helps pay whose window holds it, so several payments add up. Otherwise it stays unassigned.
- A movement **from** the office account is a refund, matched by the account alone, since the symbol of a refund is not known (`unverified`: `refund_account`): it settles the oldest unsettled period to get back that ended before it, or stays unassigned.
- A movement to the office account with **another** variable symbol is never matched on its own; it is listed in `unassigned_payments`.
- A person's assignment wins (`PUT /api/v1/vat/payments/{movementId}` with a `period`); `period: null` says the movement is no VAT payment, and `DELETE` gives it back to the automatic matching.
- Without an office account nothing is matched, and the past periods stay not yet paid. A bank source that cannot be read is listed in `unavailable_sources`.

## In the cash flow

Every period that is not settled and expects money is an **estimate flow** of the category `vat` in the forecast (source `vat.periods`): out on its due date to pay, in on its expected refund date to get back, labelled with its period, such as *VAT for November 2026*. A refund says that the tax office first uses it to pay other arrears (Tax Code section 154). A flow whose day has passed is counted on today, flagged `awaited`, never `overdue`. Once a payment or a person settles the period, its flow leaves the forecast.

The owners and members see the flows one by one, and a token only with `vat:read`; anyone else sees their category totals. Taxes and contributions never counts them.

A planned item of the category `vat` entered by hand in Cash flow may count the VAT a second time: the answer lists it in `possible_duplicates`, and only a person ends it.

## API and MCP

See [VAT in the REST API](api.md#vat) and the tools `foresi_vat_periods`, `foresi_get_vat_period`, `foresi_set_vat_settings`, `foresi_set_vat_return_amount`, `foresi_clear_vat_return_amount`, `foresi_settle_vat_period`, `foresi_reopen_vat_period`, `foresi_assign_vat_payment` and `foresi_reset_vat_payment` in [mcp.md](mcp.md). Every write records the event `vat.period.changed`.
