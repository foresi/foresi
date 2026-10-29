# 4. Money as integer minor units

* Status: accepted
* Date: 2026-10-29

## Context and problem statement

The system handles amounts of money in several currencies. Floating point arithmetic produces rounding errors, and splitting an amount (for example into instalments) must never create or lose a minor unit.

## Decision drivers

* Exact arithmetic.
* A currency always travels with its amount.
* Portable storage that behaves the same on SQLite and PostgreSQL.

## Considered options

* Integer minor units plus an ISO 4217 currency code
* Decimal columns with fixed scale
* Floating point numbers

## Decision outcome

Chosen option: "integer minor units plus currency code", because integers are exact and identical on every supported database.

### Rules

* Money is never a `float`.
* The domain type `App\Shared\Domain\Money` wraps `Brick\Money\Money`; Brick exceptions do not leak out of it.
* An amount is stored as a `BIGINT` count of minor units next to a `CHAR(3)` ISO 4217 currency code, mapped through an embeddable.
* Operations on two amounts of different currencies throw `CurrencyMismatch`.
* `allocate()` splits an amount by ratios so that the parts always sum exactly to the total. Leftover minor units are handed out one by one from the first part with a positive ratio, and a part with a zero ratio receives zero.

### Consequences

* Good: no rounding drift, and totals always add up.
* Good: simple, indexable columns.
* Bad: currencies with unusual minor units depend on the Brick currency data.
* Bad: queries cannot use database decimal functions directly on amounts.
