# 50. Currency as data

* Status: accepted
* Date: 2026-11-28
* Supersedes in part: [ADR 0004](0004-money-as-integer-minor-units.md) (which currencies exist and where their minor units come from)
* Amends: [ADR 0044](0044-public-extension-contract.md) (module API 3.0)

## Context and problem statement

`Foresi\Shared\Domain\Currency` was a backed enum with three cases, CZK, EUR and USD, and `minorUnits()` returned 2 for all of them. The database already stored every amount as `BIGINT` minor units next to a `CHAR(3)` code with no check on the code, and `Money` already passed the code to brick/money as a string. The closed set lived only in PHP: in the enum, in three hand lists that fed the REST and MCP `enum` of six inputs, and in about twenty places that divided by 100 or printed `%02d`. A firm could not invoice in Swiss francs, and an amount in yen or Bahraini dinars would have printed wrong.

The enum is part of the module contract: `BankStatement::$currency`, `IssuedDocument::$currency`, `InvoicingCountry::homeCurrency()` and many signatures that take `Money`.

## Decision drivers

* Any current ISO 4217 code works everywhere a currency is taken, with its own minor units.
* A document stored in a withdrawn currency (HRK, BGN) still loads and prints.
* One source of the list, the same one brick/money checks amounts against.
* No data migration.
* The stored integer of an amount never changes value.

## Considered options

* A final value object `Currency`, one instance per code, built from brick/money's ISO data (chosen)
* Plain strings, validated by a registry
* An enum generated from the ISO list, every case written out
* A `currency` table that a firm fills, as Odoo, ERPNext and Akaunting have

## Decision outcome

`Currency` is a final value object. `Currency::of('EUR')` returns the same instance for the same code, so `===` keeps working; it throws `UnknownCurrency` for a code that is not in the list, and `tryFrom()` returns null. It gives `code()`, `minorUnits()`, `name()`, `numericCode()`, `isHistoric()` and `equals()`. `from()` and `tryFrom()` keep the enum's meaning. The readonly `$value` stays, deprecated, for code that read the enum's value.

The list is brick/money's `IsoCurrencyProvider`, generated from the SIX lists one and three: 214 codes, current and historic, each with its minor units. `Currencies::current()` lists the current codes and `Currencies::all()` adds the historic ones. A Composer update of brick brings ISO amendments. A test pins the minor units of every code; an update that changed one fails, because a stored integer would then change value. ISO itself gives a redenomination a new code (MRO to MRU, SLL to SLE) for the same reason. The AI reader's own list of codes is gone.

Codes outside ISO (crypto, loyalty points) and the X codes without minor units are not offered. `Currency` can later be built from a row, as brick allows, as an additive minor.

The columns stay `CHAR(3)`. The Doctrine type `currency` turns the code into the value object; a mapping writes `type: 'currency'` where it wrote `enumType: Currency::class`.

Pickers offer every current code in a select with type-ahead. `Currencies::choices()` puts the firm's home currency first, then the codes the firm has used, then the rest by code; a screen passes the home and used codes it knows. There is no setting of enabled currencies.

The REST API and MCP keep money as `{"amount_minor", "currency"}` and add `"minor_units"`, so a client needs no exponent table of its own. A decimal amount in an input has at most the currency's minor units: `12.345` is valid for BHD and `12.5` is not for JPY. A currency input is a pattern `^[A-Z]{3}$` checked against the list, not a schema enum of 180 codes. `GET /api/v1/currencies` and the MCP tool `foresi_list_currencies` list the codes with their name, minor units and historic flag.

`MoneyFormat` in Shared is the one formatter: the amount with the currency's minor units, grouped for the locale, and the symbol ICU gives for that locale ("Kč" for CZK in Czech, "CZK" in English, "€" for EUR). `TypedAmount` and the Stimulus controllers take the minor units of the currency. Rounding stays half up to the minor unit; cash rounding stays in the country module.

This is a break of the module contract: `Currency::CZK`, a `match` on the cases and `instanceof \BackedEnum` no longer work, and PHP has no shim, since a class constant cannot hold an object. The module API goes to 3.0.0.

### Consequences

* Good, because a firm invoices and records documents in any current ISO currency, with no setting and no migration.
* Good, because amounts and the list come from one source, so they cannot disagree on minor units.
* Good, because old documents in a withdrawn currency still load.
* Good, because API and MCP clients get the minor units with every amount and can read the list.
* Bad, because a module of another vendor has to replace the case constants, `->value` and `cases()` (see the 3.0 upgrade notes in `docs/modules.md`).
* Bad, because an ISO amendment arrives only with a brick/money release.
* Bad, because the pickers are long; the order (home, used, the rest) and type-ahead make up for it.
