# 46. Exchange-rate sources and the legal rate

* Status: accepted
* Date: 2026-11-25
* Builds on: [ADR 0033](0033-module-contracts-and-integrations.md) (ports and integrations), [ADR 0039](0039-one-firm-per-instance.md) (one firm per instance), [ADR 0044](0044-public-extension-contract.md) (the extension contract)

## Context and problem statement

The Exchange rate module gives the rate of a currency to CZK on a date, and Purchasing, VAT, Taxes and contributions and Cash flow read it. `RateSource` has been a port since module API 1.0, but only one source could ever be used:

* `InstalledRateSource` took the first tagged source and ignored the rest.
* A stored rate was keyed by date and currency, without its source, so two sources could not keep the same day.
* Every quote meant "this many units cost so many CZK". A source that quotes against another currency, such as the European Central Bank with its rates per 1 EUR, could not be read.

The rate is not a free choice. The Czech VAT Act (Act 235/2004 Coll., section 4(8), as valid in 2026) converts a foreign currency at the rate valid for the person converting on the day the tax duty arises: either the foreign-exchange market rate of the Czech National Bank (a1), or the reference rate of the ECB, where CZK to a currency other than the euro goes through each currency's rate to the euro (a2). When neither quotes a currency, the ČNB rate of other currencies applies (b). A firm may elect the ECB, but the rates of the two may not be mixed: the rate is the one valid for that person.

How others do it, read on 2026-11-25: Odoo 19 keeps the ECB as its default provider, lets each company choose another, and stores rates per company; ERPNext has one global provider setting and falls back to a rate typed by hand; Akaunting keeps one manual rate per currency and sells live sources as an app. None of the three falls back from one provider to another on its own.

## Decision drivers

* An instance that sets nothing behaves exactly as before: a Czech firm converts at the ČNB.
* The source is the firm's choice, inside what the law of its country allows, and the audit log says who changed it.
* Any module can offer a source through the contract, and a source can quote against any currency.
* Rates of two sources never mix in one firm.
* The cross rate is computed the way the law prescribes, without rounding before the division.

## Considered options

* The base of a source: each publication names its base and Foresi converts (chosen); each source converts to CZK itself.
* Who chooses: a firm setting over the API, MCP and a settings screen (chosen); an environment variable of the operator; no choice, the country decides.
* When the source is down: no fallback (chosen); an ordered fallback for informational reads only; a fallback for every read.
* Where the legal rule lives: a port of the Exchange rate module that the country module implements (chosen); a method of `InvoicingCountry`.
* Where the ECB lives: in the Exchange rate module (chosen); a package of its own.

## Decision outcome

### The base of a publication

`PublishedRates` names its `base`, the ISO 4217 code of the currency its quotes are priced in: the ČNB `CZK`, the ECB `EUR`. A quote stays "`amount` units of the currency cost `rate` units of the base". A source that quotes the other way round writes the same fact as whole units: the ECB's "1 EUR is 1.0935 USD" is 10935 USD for 10000 EUR, so nothing is rounded when it is stored.

The rate to CZK is read from the stored quotes of the source in use. With base CZK it is the quote as published. With another base it is the cross rate through the base: CZK per base unit divided by the currency per base unit, which is the method of section 4(8) a2. It is rounded half up to six decimals, the scale of the stored rates, and given for 1, 100 or 1000 units, the fewest for which the rate is at least 1 CZK, as the ČNB quotes. Making the target the firm's home currency instead of CZK belongs to the change that turns currencies into data.

Each source has its own quotes: a stored quote is keyed by date, currency and source, and keeps its base. The quotes stored before are the ČNB's, in CZK.

`base` is a new constructor argument. Left out, it is CZK and triggers a deprecation, so a source written for module API 1.0 keeps working; it becomes required in 2.0.

### The firm's choice

The firm of the instance chooses its source: `exchange_rate_setting` holds one row (ADR 0039), changed with `PUT /api/v1/exchange-rate/source`, the MCP tool `foresi_set_exchange_rate_source` and Settings → Exchange rates, by the owners and the members. A change of the source in use records `exchange-rate.source.changed` with the source chosen and the one before; the audit log keeps the row's history. The source in use is, in this order:

1. the one the firm chose, while it is installed and its country's rules allow it;
2. the default of the rules of the firm's country, while it is installed;
3. the first installed source.

Without rules for the firm's country every installed source is allowed. The ECB comes after every other source, so without a choice a country's own source stays the default. The daily task fetches only the source in use; `app:exchange-rate:fetch --source=<name>` fetches any installed source by hand, for instance before the firm switches.

### No fallback

When the source in use is down, Foresi does not ask another one. What already exists covers the gap: the last publication holds over weekends and holidays, the daily task retries every ten minutes, a received document can take a typed rate, and the failure is logged as a warning. An ordered fallback for informational reads, such as the cash-flow forecast, can be added later without a break.

### The legal rule

`Foresi\ExchangeRate\Contract\RateRules` (tag `foresi.exchange_rate.rules`) gives, for one country, the sources a firm there may choose (`allowedSources()`) and the one it uses until it chooses (`defaultSource()`). The rules of the firm's country apply, as with `VatCountryRules`. The Czech country module allows `cnb` and `ecb` and defaults to `cnb`, citing section 4(8). A source the rules do not allow is refused with `source_not_allowed`, one that is not installed with `source_unknown`.

### The ECB

`EcbRateSource` lives in the Exchange rate module, the neutral source that needs no country module. It reads today from `eurofxref-daily.xml` and an earlier date from `eurofxref-hist-90d.xml` of `www.ecb.europa.eu`, through `PublicRegistryClient` like the ČNB, each answered with the last publication on or before the date. A date older than those 90 days has no ECB rate; the full history is larger than the client accepts.

### Module API 1.1

`PublishedRates::$base`, its constructor argument and `RateRules` are additions, so the module API goes from 1.0.0 to 1.1.0. Nothing is removed.

### Consequences

* Good, because an instance without a setting converts as before, and a Czech firm can elect the ECB as the law allows.
* Good, because any module can add a source in any base, and the rule of what a firm may choose sits with its country.
* Good, because two sources never mix in one firm, and switching changes only rates taken from then on: a document keeps the rate it was saved with.
* Bad, because the ECB gives no rate for a date older than 90 days; such a document needs a typed rate.
* Bad, because a firm whose source is down waits or types the rate, as before.
* Open: whether Czech law binds a choice for a whole year, and whether income tax allows the ECB (section 38 of the Income Taxes Act points to the rate of the Accounting Act). Until an accountant confirms both, the setting applies to every conversion.
