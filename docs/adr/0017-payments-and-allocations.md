# 17. Payments and allocations

* Status: accepted
* Date: 2026-11-02

## Context and problem statement

An invoice has to show whether it is paid, partly paid or overpaid, from payments recorded by hand or from bank movements. One payment can settle several invoices, an invoice can be paid in parts, and every payment must be removable again. The invoice stays editable after payment, so what counts as paid has to follow an edit. Two notices for one invoice, or a person and the matcher on one movement, can arrive at the same moment.

## Decision drivers

* One source of truth: what is paid must never disagree with the payments that exist.
* A payment over several invoices, and several payments on one invoice.
* Every pairing can be undone, and an undone pairing is not redone by a machine.
* Exactly one payment per bank movement, also under races, on SQLite and PostgreSQL.
* Country rules stay in the country module.

## Considered options

* Payments and allocations, with the paid amount derived in SQL (chosen)
* A stored paid amount or paid state on `invoice`
* A payment tied to one invoice, without allocations
* Small differences: none, up to 0.99 of the currency unit in CZK (chosen), up to 1.99 CZK

## Decision outcome

**Payment and allocation.** A `Payment` (firm, date received, amount, method, source, optional bank movement, note, cancelled at and by) is split over invoices by `PaymentAllocation` rows (payment, invoice, amount, settlement). The allocated amounts of a payment add up to its amount. A settlement is outside that sum and counts toward the invoice alone.

**Derived, never stored.** `paid(invoice)` is the sum of `amount + settlement` over the allocations of uncancelled payments, computed with one grouped subquery per list page. `PaymentState::of(payable, paid)` gives Unpaid, PartlyPaid, Paid or Overpaid; a payable of zero or less is always Unpaid. The status order is Trashed > Overpaid > Paid > Overdue > PartlyPaid > Delivered > Issued, and overdue applies only while something is left to pay. A stored paid column was rejected: it would have to be kept in step with every payment, cancellation and invoice edit, and the status of an invoice is already derived the same way. An edit after payment re-derives the state, so a raised total turns Paid into PartlyPaid.

**Cancel, never delete.** Removing a payment cancels it and deletes its allocations; the payment row stays with who cancelled it and when. A bank payment releases its movement through a port Invoicing declares (`PaymentSourceRelease`), which Banking implements: the movement returns to Unmatched with automatic pairing blocked, so the matcher does not undo the undo. An invoice with a live allocation cannot be trashed.

**One live payment per movement.** The payment keeps its movement a second time, in a nullable `live_bank_movement_id` with a unique index, set only while the payment is not cancelled. The database itself then refuses a second live payment for one movement, without a partial index, which SQLite and PostgreSQL would express differently.

**Locks.** Recording takes the invoices' locks in ascending id order; pairing a movement takes the movement's lock first, then its invoices in the same order, and decides again under the locks. A real-process race test covers two notices for one invoice and an automatic against a manual pairing.

**Small differences.** Only when the firm's switch is on (default off), a single invoice is paid, and the firm's country module gives a limit for the currency: `InvoicingCountry::settlementLimit(Currency): ?int`. The Czech module gives 99 minor units in CZK and none otherwise. The allocation records the amount that came, and the settlement is the remaining amount minus it, either way, so the invoice closes exactly. Fakturoid's tolerance of 1.99 CZK was rejected: the custom it serves is the coins nobody pays by transfer, which end below one crown, and the specification asks for a difference under 1 CZK. A rule in the core was rejected for a country custom.

**Matching rules.** Incoming movements pair by the variable symbol (digits, leading zeros ignored) and the amount, under a gate (verified message, currency of the account, automatic pairing on, not unpaired before): one candidate takes the whole amount; of several, the one whose remaining amount equals the movement, or else all of them when the movement is their sum; anything else waits for a person, with suggestions. An invoice issued after the money arrived is only suggested. Own transfers, outgoing movements and possible duplicates never pair. See [bank-pairing.md](../bank-pairing.md#what-pairs-by-itself).

### Consequences

* Good: paid amounts cannot drift; every pairing can be undone; the race is closed by the database as well as by locks.
* Bad: every list page runs the grouped subquery; the paid state cannot be indexed. A third-party country module must implement `settlementLimit()`.
* Tax documents on payment, advances and foreign-currency conversion are deferred; a payment keeps date, amount, currency and method, which is what they need.
