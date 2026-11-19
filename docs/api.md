# REST API

Foresi has a REST API. Scripts and other systems use it to read the data of an organisation and to change its contacts. The decisions behind it are in [ADR 0027](adr/0027-api-and-mcp.md) and [ADR 0032](adr/0032-api-writes.md). Assistants use the [MCP server](mcp.md), which is built on the same code.

The API lists and shows invoices, contacts and payments, and follows the feed of domain events. It keeps the [cash flow](#cash-flow) of a firm and answers its forecast. It writes [contacts](#contact-writes) and [webhook endpoints](#webhooks): addresses of your own that receive the events as signed requests.

## Personal API tokens

Every request is signed in with a personal API token. A token belongs to one person in one organisation. It acts as that person: it sees what the person sees and nothing more. Guests cannot have tokens.

### Issue a token

1. Sign in and open **Settings > API and MCP**.
2. Click **New token**.
3. Enter a name (where you will use it, such as `Reporting`) and choose how long it lasts: 30, 90 or 365 days.
4. Under **Access**, choose what the token may do: read everything, read and change everything, or read or change one kind of record, such as contacts.
5. Click **Create token** and copy the token.

The token is shown **once**. Foresi keeps only a hash, so it cannot show the token again. If you lose it, revoke it and issue a new one.

Rules:

- Only a person who has set up two-factor sign-in can issue a token. Without it the page shows a notice with a link to set it up.
- Give a token only the [scopes](#scopes) its program needs. An outreach agent that keeps contacts needs `contacts:write`, not `write`.
- A token always expires. There is no token without an end date.
- You see your own tokens. An owner also sees the tokens of the other people in the organisation and can revoke them.
- Issuing and revoking a token are recorded in the audit log. What a token does is recorded under the token, next to the person.

### Revoke a token

Open **Settings > API and MCP**, open the row menu of the token and choose **Revoke**. The token stops working at once. An expired or revoked token stays in the list, marked as such.

### Console commands for operators

The operator can manage tokens from the console. No two-factor sign-in is asked for here: whoever runs the console already holds the installation.

```bash
# Issue a token for a person.
bin/console app:api-token:issue anna@example.com --name="Reporting" --days=90 --scope=read
bin/console app:api-token:issue anna@example.com --name="Outreach" --scope=contacts:write

# List every token. Only the last four characters of a token are known.
bin/console app:api-token:list

# Revoke a token by its id.
bin/console app:api-token:revoke <token-id>
```

`issue` takes `--days` of 30, 90 or 365 (90 by default) and one or more `--scope`, which it requires: `read`, `write` or a resource scope such as `contacts:write` (see [Scopes](#scopes)). A scope no installed module declares is refused. The person must be the owner or a team member. The command prints the token once.

### Scopes

A scope says what a token may reach. A token has one or more:

| Scope | Grants |
| --- | --- |
| `read` | Every `:read` scope. |
| `write` | Everything, reading included. |
| `<resource>:read` | Reading one kind of record. |
| `<resource>:write` | Changing one kind of record, and reading it. |

The resources are `cashflow`, `contacts`, `invoices`, `payments`, `received_documents`, `events`, `views` and `webhooks`; each module declares its own. Every endpoint needs one resource scope, named in the tables below. `GET /api/v1/me` needs a valid token and no scope. A request without the scope it needs is answered `403 insufficient_scope`, and `WWW-Authenticate` names the scope. Tokens issued before scopes per resource keep what they had: `read` reads everything, and `write` now also reads.

## Using the API

The base URL is `/api/v1` on the address of your installation. Send the token as a bearer token:

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" "https://app.example.com/api/v1/invoices?state=unpaid"
```

- The API is stateless. It never uses cookies or CSRF tokens.
- Answers are JSON (`application/json`), errors `application/problem+json`.
- Put the token in an environment variable or a secret store, never in a URL.
- The organisation comes from the token. The firm is part of the data (`firm` on an invoice or payment); filter by `firm_id` to read one firm.

To check a set-up, call `GET /api/v1/me`. It returns the person and the token (its name, scopes and expiry). It never returns the secret.

### Money, dates and ids

- Money is an object with the amount in minor units: `{"amount_minor": 12100, "currency": "CZK"}` is 121.00 CZK.
- Dates are `YYYY-MM-DD`. Timestamps are ISO 8601 with an offset, such as `2026-11-10T14:30:00+01:00`.
- Quantities, percentages and discounts are strings with fixed decimals, such as `"1.0000"` and `"21.00"`.
- Ids are UUIDs.
- A field with no value is `null`, not left out.

### Lists and paging

A list answers with an envelope:

```json
{
  "data": [ { "id": "...", "number": "20260042" } ],
  "next_cursor": "MDE5MmYw..."
}
```

Lists are sorted newest first. `limit` is the size of a slice, 25 by default and at most 100. When `next_cursor` is not `null`, send it back as `cursor` to get the next slice, with the same filters. When it is `null`, you have everything (except in the events feed, see below). The cursor is opaque: do not build or change it. A cursor this API did not give is refused with `invalid_parameter`.

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50"

curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50&cursor=MDE5MmYw..."
```

A detail answers `{"data": {...}}`.

## Endpoints

The endpoints below are `GET`. The writes of invoices are under [Invoice writes](#invoice-writes), those of contacts under [Contact writes](#contact-writes), those of saved views under [Saved views](#saved-views), those of the cash flow under [Cash flow](#cash-flow), those of received documents under [Received documents](#received-documents), and those of webhooks under [Webhooks](#webhooks).

| Endpoint | Scope | What it returns |
| --- | --- | --- |
| `/api/v1/me` | none | The person and the token. |
| `/api/v1/invoices` | `invoices:read` | Sales documents, newest first. |
| `/api/v1/invoices/{invoiceId}` | `invoices:read` | One document with its lines, payments, corrections and balances. |
| `/api/v1/contacts` | `contacts:read` | Contacts, newest first. |
| `/api/v1/contacts/{contactId}` | `contacts:read` | One contact with its addresses, e-mails, bank accounts and contact persons. |
| `/api/v1/payments` | `payments:read` | Payments, newest first. |
| `/api/v1/payments/{id}` | `payments:read` | One payment with the invoices it pays. |
| `/api/v1/received-documents` | `received_documents:read` | Received documents, newest first, with what is paid and what remains. |
| `/api/v1/received-documents/{documentId}` | `received_documents:read` | One received document with its VAT recap and payments. |
| `/api/v1/received-documents/{documentId}/payments` | `received_documents:read` | The payments of one received document, by the day paid. |
| `/api/v1/events` | `events:read` | Domain events of the last 30 days, oldest first. |
| `/api/v1/cash-flow/forecast` | `cashflow:read` | The cash-flow forecast of a firm; see [Cash flow](#cash-flow). |
| `/api/v1/cash-flow/planned-items` | `cashflow:read` | The planned items of a firm (`firm_id`, required), oldest first, without paging. |
| `/api/v1/cash-flow/planned-items/{plannedItemId}` | `cashflow:read` | One planned item. |
| `/api/v1/lists` | `views:read` | The lists saved views filter, with their fields, operators, values and columns; see [Saved views](#saved-views). |
| `/api/v1/views?list=` | `views:read` | The saved views of a list the token's person sees. |
| `/api/v1/views/{viewId}` | `views:read` | One saved view, by its id or the system key of one a module ships. |
| `/api/v1/views/{viewId}/count` | `views:read` and the list's scope | How many records the view holds now. |

### Invoices

Query parameters of `GET /api/v1/invoices`. All of them narrow the list.

| Parameter | Value |
| --- | --- |
| `kind` | `invoice`, `advance`, `payment_tax_document` or `correction_document`. |
| `firm_id` | The id of a firm. |
| `customer_id` | The id of a contact. |
| `state` | `unpaid`, `overdue` or `paid`. |
| `trashed` | `true` lists documents in the trash. Default `false`. |
| `issued_from`, `issued_to` | Issue date range, `YYYY-MM-DD`, both ends included. |
| `q` | Text to search for. |
| `view` | The id of a saved view of a list of documents, or the system key of one a module ships, such as `invoicing.invoices.overdue`: only the documents it holds. Needs `views:read` too. Without `kind`, the kind of the view's list. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), such as `{"conditions": [{"field": "due_date", "op": "before", "value": {"relative": "today"}}]}`. Checked against the list of `kind`, or of the invoices without one. |
| `limit`, `cursor` | Paging, as above. |

All parameters are joined by AND, `view` and `filter` included. The answer has `data`, `next_cursor` and `applied_view`, the saved view `view` named, or `null`. A filter that cannot be used is answered `400 invalid_parameter`, with `parameter` naming its path, such as `filter.conditions[0].op`, and `reason` `filter_invalid`; a view of another list than `kind` is refused the same way with `parameter` `view`. A view the person does not see is `404 not_found`, and one whose conditions its list no longer has is `409 view_outdated`.

A list item has `id`, `number`, `kind`, `firm`, `customer`, `issue_date`, `due_date`, `tax_point_date`, `total`, `paid`, `remaining`, `payment_state` (`unpaid`, `partly_paid`, `paid`, `overpaid` or `to_refund`), `overdue`, `delivered`, `trashed` and `created_at`. The detail adds the customer's identification and address, the currency, the variable symbol, the lines with their VAT, the payments and corrections, and the balances.

### Invoice writes

Issuing and changing sales invoices and advance invoices needs `invoices:write`. Foresi has no drafts: a created invoice is issued at once, with the next number of its series, as saving the invoice form does. The writes run the same rules as the form, and answer `{"data": {...}}` with the document as `GET /api/v1/invoices/{invoiceId}` shows it.

| Method and path | What it does |
| --- | --- |
| `POST /api/v1/invoices` | Issues an invoice. `201` with `Location`. |
| `PATCH /api/v1/invoices/{invoiceId}` | Changes the fields given; a field left out keeps its value. |
| `POST /api/v1/invoices/{invoiceId}/trash` | Moves it to the trash with a `reason`. |
| `POST /api/v1/invoices/{invoiceId}/restore` | Brings it back from the trash. |

The body of `POST /api/v1/invoices`:

| Field | Value |
| --- | --- |
| `customer_id` | Required. The id of a contact with a billing address. |
| `lines` | Required. Objects of `text`, `unit_price` (a decimal string such as `"1500.00"`), and optional `quantity` (default `"1"`), `unit`, `price_type` (`without_vat` by default, `with_vat` or `base_only`), `vat` (`base`, `reduced`, `second_reduced`, `reverse_charge`, `exempt` or `none`) and `discount` (a percentage, such as `"10"`). Without `vat` a line gets the base rate, reverse charge for a customer taxed instead, or `none` for a firm that is no VAT payer. |
| `firm_id` | The firm that issues it; the firm of the instance when left out. |
| `kind` | `invoice` (default) or `advance`. |
| `series_id` | A series of the firm and kind; the default series when left out. |
| `number` | A number typed by hand, such as one continuing the numbering of another system; it must fit the series and be free. Without it the series' counter gives the next number. |
| `issue_date`, `tax_point_date`, `due_date` | `YYYY-MM-DD`. Today, the issue date, and the issue date plus the customer's due days when left out. |
| `currency` | ISO 4217; the currency of the firm when left out. |
| `payment_method` | `bank_transfer`, `cash` or `card`; the customer's when left out. |
| `bank_account` | The IBAN of an account of the firm; its primary account when left out. |
| `variable_symbol` | Up to 10 digits; it follows the number when left out. |
| `constant_symbol`, `text_before`, `text_after` | The firm's defaults when left out. |
| `order_number`, `description` | The customer's order number, and what the invoice is for (the first line's text when left out). |
| `language` | `cs` or `en`; the customer's when left out. |

`PATCH` takes the same fields except `firm_id`, `kind` and `currency`, which never change. `lines` replaces every line; left out, the lines stay as they are. `series_id` or `number` renumber the document, which is refused once it was delivered (`number_locked`), sent by e-mail (`number_locked_sent`) or queued to be sent (`number_locked_sending`); a variable symbol that followed the number follows the new one. A document in the trash (`trashed`) and a tax document on payment (`tax_document_fixed`) do not change.

What the rules refuse is answered with every problem at once: the first one gives the status, the `reason` and, for `400`, the `parameter`, and `problems` lists them all as `"parameter: reason"`, such as `["variable_symbol: variable_symbol_invalid", "customer_id: customer_unknown"]`. A line is named by its position, such as `lines[0].unit_price`. A state of the data that does not allow the change, such as `number_taken`, `number_locked` or `trashed`, is `409`. The trash refuses a delivered document and one already in the trash with `409 not_trashable`, and one with payments with `409 has_payments`; correct a delivered document with a correction document in Foresi.

### Contacts

Query parameters of `GET /api/v1/contacts`:

| Parameter | Value |
| --- | --- |
| `q` | Text to search for. |
| `role` | `customer`, `supplier`, `partner` or `authority`. |
| `archived` | `true` lists archived contacts. Default `false`. |
| `registration_number` | Company registration number. |
| `vat_id` | VAT id. |
| `view` | The id of a saved view of the list `party.contacts`, or the system key of one it ships, such as `party.contacts.suppliers`: only the contacts it holds. Needs `views:read` too. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), such as `{"conditions": [{"field": "role", "op": "is", "value": "supplier"}]}`. A condition on `archived` decides it instead of the `archived` parameter. |
| `limit`, `cursor` | Paging, as above. |

The answer has `data`, `next_cursor` and `applied_view`, and refuses a view or filter as the invoices do; a view of another list is refused with `parameter` `view`.

### Payments

Query parameters of `GET /api/v1/payments`:

| Parameter | Value |
| --- | --- |
| `firm_id` | The id of a firm. |
| `invoice_id` | The id of an invoice the payment pays. |
| `direction` | `incoming` or `refund`. |
| `received_from`, `received_to` | Date received range, `YYYY-MM-DD`, both ends included. |
| `include_cancelled` | `true` also lists cancelled payments. Default `false`. |
| `limit`, `cursor` | Paging, as above. |

### Events

`GET /api/v1/events` is the feed of what happened in the organisation: an invoice was issued, delivered, paid, trashed or restored, a payment was recorded, a contact was created, updated, archived or restored, a received document was recorded or paid in full. Query parameters:

| Parameter | Value |
| --- | --- |
| `types` | Event names or `module.*`, separated by commas, such as `invoicing.invoice.paid,invoicing.payment.recorded`. Without it, every event the token's person may read. An unknown type is `400 invalid_parameter`; a type of a module the person may not read is `403 forbidden`. |
| `limit`, `cursor` | Paging, but the cursor is a position in the feed, and `next_cursor` is not `null` once you have seen an event; see below. |

An item has `id`, `type`, `version`, `position`, `occurred_at`, `subject` (`type`, `id` and the API path `url` to read it), `data`, `correlation_id` and `actor` (`kind`). The `data` holds ids, codes and amounts as text, and no personal data. The feed is in ascending order of `position`, and the events of the last 30 days are kept. To follow it, store the `next_cursor` of the last answer and call again with it as `cursor`: it continues after the last event you got. A page with fewer items than `limit` means you are caught up; call again later with the same `cursor`, and `next_cursor` stays where it was until something new happens. `next_cursor` is `null` only while you have seen no event at all. Delivery to you is at least once, so handle an `id` you have seen before. A type only gains facts within a `version`.

A value an endpoint cannot use, such as a date that is not a date or a `limit` above 100, is answered `400 invalid_parameter`, with the name of the parameter.

## Saved views

A saved view is a named filter of a list, with its sort and columns, such as "Due this week" on the invoices. It is private to its owner or shared with every owner and member; the views a module ships, such as the tabs of the invoice lists, are shared and have a `system_key`, such as `invoicing.invoices.overdue`, which names them wherever an id does. Guests never see views. The reads need `views:read`, the writes `views:write`, and reading the records of a view also needs the scope of its list, such as `invoices:read`; the bank movements (`banking.movements`) are read with `payments:read`, by staff only.

`GET /api/v1/lists` describes every list views filter, so a client builds a filter without guessing. Each list has its `key`, its `fields` (`key`, `type`, `label`, the `operators` it takes and, for an enum, its `options`), its `columns` (`key`, `label`, `sortable`, `default`, `locked`) and its `default_views`. `date_values` lists the date values every date field takes.

A filter is `{"match": "all", "conditions": [{"field", "op", "value"}]}`, every condition met, at most 20; `match` may be left out. The operators by type:

| Type | Operators | Value |
| --- | --- | --- |
| `enum` | `is`, `is_not`, `is_any_of`, `is_none_of` | One of `options`, or a list of them. |
| `reference` | `is`, `is_not`, `is_any_of`, `is_empty`, `is_not_empty` | An id, or a list of ids. |
| `text` | `contains`, `does_not_contain`, `is`, `is_empty` | A text. |
| `number` | `eq`, `ne`, `lt`, `le`, `gt`, `ge`, `between` | A number. |
| `date` | `is`, `before`, `after`, `on_or_before`, `on_or_after`, `between`, `is_empty` | A day `YYYY-MM-DD`, a month `{"month": "YYYY-MM"}` or a relative date. |
| `boolean` | `is` | `true` or `false`. |

`between` takes `{"from": …, "to": …}`, both ends included; `is_empty` and `is_not_empty` take no value. A relative date is resolved when the query runs, in the organisation's time zone: `{"relative": "today"}`, likewise `yesterday`, `tomorrow`, `this_week`, `last_week`, `this_month`, `last_month`, `next_month`, `this_quarter`, `last_quarter`, `this_year` and `last_year`, or a count of days from 1 to 365: `{"relative": "next_n_days", "n": 7}`, `last_n_days`, `n_days_ago` and `n_days_from_now`. A period with `is` covers all of it; `before` is before it starts and `after` after it ends.

A view has `id`, `list`, `name`, `system_key`, `visibility` (`private` or `shared`), `owner_id`, `filters`, `sort`, `display`, `show_count`, `placement` (`primary` or `overflow`), `position`, `hidden`, `may_edit` (whether the token's person may change it), `created_at` and `updated_at`.

| Method and path | Body | What it does |
| --- | --- | --- |
| `POST /api/v1/views` | `list_key`, `name`, optional `visibility` (`private`), `conditions`, `sort` (`{field, dir}`, at most 3), `columns` (column keys or `{key, width}`; `null` for the list's default), `density`, `show_count`, `placement` | Creates a view, `201` with its `Location`. Any owner or member may share one. |
| `PATCH /api/v1/views/{viewId}` | Any of the fields above but `list_key`, plus `position` and `hidden` | Changes the fields sent; `conditions`, `sort` and `columns` replace what the view had. A shared view is changed by its creator and the owners, a view a module ships by the owners only, who also hide it with `hidden`. |
| `DELETE /api/v1/views/{viewId}` | none | Deletes a view for everyone. A view a module ships is refused with `409 default_view`. |
| `POST /api/v1/views/restore-defaults` | `list_key` | Brings the views a module ships on a list back to what it declares. Owners only. |

The writes take an `Idempotency-Key` (see [Idempotency](#idempotency)) and are in the audit log. A condition that cannot be used is answered `400 invalid_parameter` with `parameter` naming its path, such as `conditions[1].value.relative`, and `reason` `filter_invalid`; a person who may not change a view gets `403 forbidden`.

Rows as a view shows them, with only its columns, are not served yet; list the records of a view through the endpoint of its list, such as `GET /api/v1/invoices?view=`.

## Contact writes

These endpoints need the `contacts:write` scope. Each answers `{"data": {...}}` with the contact as `GET /api/v1/contacts/{contactId}` shows it; for a link or an unlink, that is the company with its contact persons. Bodies are JSON (`Content-Type: application/json`) with snake case fields. The rules are those of the address book screens, and the owners and team members of the organisation may write.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/contacts` | `201` with the new contact and `Location`. |
| `PATCH /api/v1/contacts/{contactId}` | The contact as it is now. |
| `POST /api/v1/contacts/{contactId}/archive` | The archived contact. Archiving it again changes nothing. |
| `POST /api/v1/contacts/{contactId}/restore` | The restored contact. Restoring it again changes nothing. |
| `POST /api/v1/contacts/{contactId}/contact-persons` | The company with the person linked. |
| `DELETE /api/v1/contacts/{contactId}/contact-persons/{personId}` | The company without the link. Both contacts stay. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 5b0e1c2a-create-alpha" \
  -d '{"kind": "company", "display_name": "Alpha Example s.r.o.", "registration_number": "12345679", "email": "alpha@example.com", "roles": ["customer"], "street": "Example Street 1", "city": "Example City", "postal_code": "100 00", "country_code": "CZ"}' \
  "https://app.example.com/api/v1/contacts"
```

Fields of a contact:

| Field | Value |
| --- | --- |
| `kind` | Required on create. `person`, `sole_trader` or `company`. It does not change later. |
| `display_name` | Required. At most 255 characters. |
| `street`, `city`, `postal_code`, `country_code` | Required. The billing address; every contact has one. The country is ISO 3166-1 alpha-2, such as `DE`. |
| `registration_number` | The company registration number, checked by the rules of its country. A company needs it or a `vat_id`. |
| `registration_country` | The country that issued the number. The country of the billing address when left out. |
| `vat_id` | Two letters of the country, then the number. |
| `email` | The general e-mail address. |
| `phone`, `mobile`, `website` | Text. |
| `roles` | A list of `customer`, `supplier`, `partner` and `authority`. An update replaces the list. |
| `private_note` | A note for the staff, never printed. |

`PATCH` changes only the fields sent. A field left out keeps its value; an optional field sent as `null` is cleared. The fields of a link are `person_id` (a contact of kind `person`), `position` (at most 100 characters) and `primary` (`true` makes it the company's primary contact person, in place of the previous one).

What a contact write refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field breaks a rule. `parameter` names it and `reason` says which rule: `required`, `too_long`, `kind_invalid`, `role_invalid`, `country_unsupported`, `email_invalid`, `registration_number_invalid`, `vat_id_invalid`, `company_identifier_required` or `person_invalid`. |
| 404 | `not_found` | The organisation has no such contact. |
| 404 | `not_linked` | The person is not linked to the company. |
| 409 | `duplicate` | Another contact has the registration number or the VAT id. `existing_ids` lists the contacts it matched. |
| 409 | `already_linked` | The person is linked to the company already. |

There is no upsert. To keep a contact you may have created before, search for it first (`q` also matches e-mails), and on `duplicate` read the contact in `existing_ids` and update it.

## Cash flow

The cash flow of a firm: planned items, bank balance checkpoints, the expected days of documents, the minimum balance and the forecast. What each one means is in [cash-flow.md](cash-flow.md). The writes need `cashflow:write`, the reads `cashflow:read`, and the owners and team members of the organisation may use them. Bodies are JSON with snake case fields.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/cash-flow/planned-items` | `201` with the new planned item and `Location`. |
| `PATCH /api/v1/cash-flow/planned-items/{plannedItemId}` | The item as it is now. A field left out keeps its value; an optional field sent as `null` is cleared, so `"repeat": null` makes it one-off. The firm and the direction do not change. |
| `POST /api/v1/cash-flow/planned-items/{plannedItemId}/skip` | The item with the day `on` among `skipped_on`. The day must be one the item falls on. |
| `POST /api/v1/cash-flow/planned-items/{plannedItemId}/end` | The recurring item ending on the day `on`. A one-off item is refused with `409 not_recurring`. |
| `DELETE /api/v1/cash-flow/planned-items/{plannedItemId}` | `{"id": ..., "deleted": true}`. The item and its occurrences are gone. |
| `POST /api/v1/cash-flow/checkpoints` | `201` with the checkpoint: `bank_account_id`, `on` (today or before) and `amount` in the currency of the account. The same account and day again corrects it. |
| `POST /api/v1/cash-flow/expected-dates` | The expected day: `subject_type` and `subject_id` as a forecast item names its subject, and `expected_on`. Setting it again moves it. |
| `DELETE /api/v1/cash-flow/expected-dates/{subjectType}/{subjectId}` | The subject with `expected_on: null`. Clearing one that is not set changes nothing. |
| `POST /api/v1/cash-flow/firms/{firmId}/minimum-balance` | The minimum balance in CZK (`amount`); `null` removes it. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"firm_id": "0198a4f0-8b1e-7000-8000-000000000001", "direction": "out", "name": "Office rent", "category": "rent", "amount": "15000.00", "on": "2026-12-15", "repeat": "month"}' \
  "https://app.example.com/api/v1/cash-flow/planned-items"

curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/cash-flow/forecast?firm_id=0198a4f0-8b1e-7000-8000-000000000001"
```

Fields of a planned item:

| Field | Value |
| --- | --- |
| `firm_id` | Required on create. The firm the money belongs to. |
| `direction` | Required on create. `in` or `out`. |
| `name` | Required. At most 120 characters. |
| `category` | Required. `revenue`, `purchases`, `rent`, `software`, `payroll`, `taxes`, `insurance`, `vat`, `loans`, `owner_draws` or `other`. |
| `amount` | Required. The amount of one occurrence, more than zero, as a decimal string such as `"1500.00"`. |
| `currency` | `CZK` (the default), `EUR` or `USD`. |
| `on` | Required. The day of a one-off item, or the first day of a recurring one. |
| `repeat` | `day`, `week`, `month` or `year`; left out or `null` for a one-off item. |
| `every` | 1 to 99, 1 by default. |
| `last_day_of_month` | `true` puts a monthly or yearly item on the last day of its month. |
| `ends_on`, `ends_after` | The last day an occurrence may fall on, or a count of 1 to 999 occurrences; not both. |
| `counterparty_id` | A contact the money comes from or goes to. |
| `included` | `false` leaves the item out of the forecast; `true` by default. |
| `match_account`, `match_variable_symbol`, `match_tolerance` | The counterparty account, a variable symbol of up to 10 digits and how far a payment may differ, a bank movement that meets them replaces the planned occurrence of its period. |

Query parameters of `GET /api/v1/cash-flow/forecast`:

| Parameter | Value |
| --- | --- |
| `firm_id` | Required. The firm. |
| `from` | The first day, today or later. Today by default. |
| `months` | How many calendar months the forecast covers, 1 to 24. 12 by default. |
| `weeks_until` | Weeks are used up to the week that holds this day, then months. The end of next month by default. |

The forecast answers `firm`, `currency` (`CZK`), `today`, `from`, `to`; `balance` with its `amount`, `as_of`, `stale` and the `accounts` (each with its id, IBAN, balance in its own currency, the amount in CZK, `as_of` and the `source` that knew it); `periods`, each with `key` (`2026-W47` or `2027-01`), `kind`, `start`, `end`, `opening`, `inflows` and `outflows` (`total` and `by_category`), `closing` and its `items`; `lowest` (`amount` and `on`), `minimum_balance`, `shortfall_on`, `incomplete`, `unavailable_sources`, `unavailable_rates` and `restricted_sources`. An item has its `source`, `subject` (`type`, `id`, `url`), `direction`, `label`, `category`, `certainty`, `flags` (`overdue`, `expected_date`, `estimated`, `no_due_date`), `expected_on`, `counted_on`, `amount` in its own currency, `amount_czk` and `counterparty_id`.

The items come from the sources of the installed modules: planned items, issued and recurring invoices, received documents. A planned occurrence that a document or a bank movement already stands for is left out. A source the token may not read in detail still counts in the totals and the balance: `cashflow:read` alone shows no invoices without `invoices:read` and no received documents without `received_documents:read`. Such a source is named in `restricted_sources`, and each period lists its flows in `hidden_items`, each with `source`, `direction`, `category`, `amount_czk` and the `count` of items. See [cash-flow.md](cash-flow.md#sources).

What the cash flow refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field or parameter breaks a rule. `parameter` names it and `reason` says which rule, such as `name_required`, `amount_invalid`, `amount_not_positive`, `date_invalid`, `date_in_future`, `repeat_unknown`, `recurrence_invalid`, `end_ambiguous`, `not_recurring`, `not_an_occurrence`, `ends_before_start`, `counterparty_unknown`, `firm_required`, `from_in_past` or `months_invalid`. |
| 403 | `forbidden` | A guest of the organisation. |
| 404 | `not_found` | The organisation has no such firm, bank account or planned item. |
| 409 | `not_recurring` | Ending a one-off item. |

## Received documents

The documents the firms received from suppliers (see [received documents](purchasing.md)). Reading needs `received_documents:read`, writing `received_documents:write`. An item has the firm, `state` (`draft` or `recorded`), `kind`, `treatment`, the evidence number, the supplier, the supplier's number, the variable symbol, the dates, `total` (negative for a credit note), `base`, `vat`, and the derived `paid`, `remaining` (both positive), `is_paid` and `last_paid_on`. The detail adds the supplier as the document names it, `vat_recap`, the exchange rate, the deduction period and `payments`.

Query parameters of `GET /api/v1/received-documents`:

| Parameter | Value |
| --- | --- |
| `firm_id` | The id of a firm. |
| `supplier_id` | The id of the supplier, a contact. |
| `state` | `draft` or `recorded`. |
| `unpaid` | `true` lists only recorded documents with something left to pay. Default `false`. |
| `due_before` | `YYYY-MM-DD`: only documents due before this day, not on it. |
| `trashed` | `true` lists only the documents in the trash. Default `false`. |
| `view` | The id of a saved view of the list `purchasing.received`, or the system key of one it ships, such as `purchasing.received.drafts`: only the documents it holds. Needs `views:read` too. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), such as `{"conditions": [{"field": "kind", "op": "is", "value": "credit_note"}]}`. A condition on `trashed` decides it instead of the `trashed` parameter. |
| `limit`, `cursor` | Paging, as above. |

The answer has `data`, `next_cursor` and `applied_view`, and refuses a view or filter as the invoices do; a view of another list is refused with `parameter` `view`.

The payables of a month are `?unpaid=true&due_before=` the first day of the next month.

The writes answer `{"data": {...}}` with the document as `GET /api/v1/received-documents/{documentId}` shows it, payments included:

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/received-documents` | `201` with the new recorded document and `Location`. |
| `POST /api/v1/received-documents/{documentId}/trash` | The document in the trash. Body: `reason`. |
| `POST /api/v1/received-documents/{documentId}/payments` | `201` with the document and its new payment. |
| `POST /api/v1/received-documents/{documentId}/mark-paid` | The document paid in full by one payment of all that remains, dated today in the organisation's time zone. |
| `DELETE /api/v1/received-documents/{documentId}/payments/{paymentId}` | The document without the payment, for one recorded by mistake. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 7c1d-record-fv-2026-123" \
  -d '{"firm_id": "…", "kind": "tax_invoice", "supplier_id": "…", "supplier_number": "FV-2026-123", "issue_date": "2026-11-02", "due_date": "2026-11-16", "total": "1210.00", "vat_recap": ["21:1000.00:210.00"]}' \
  "https://app.example.com/api/v1/received-documents"
```

Recording takes the next evidence number of the firm, as the screen does, and needs no file; the original can be uploaded to the document in Foresi later. Fields of a record:

| Field | Value |
| --- | --- |
| `firm_id`, `kind`, `supplier_id`, `issue_date`, `total` | Required. `kind` is `tax_invoice`, `simplified_receipt`, `foreign_invoice`, `credit_note`, `advance_request` or `other`. The supplier is a contact; find it by `registration_number` first, or create it with a contact write. |
| `treatment` | `domestic` (default), `domestic_reverse_charge`, `eu_service`, `non_eu_service`, `non_deductible`, or `not_vat_document` for a document without VAT. |
| `supplier_number`, `variable_symbol` | The supplier's number of the document and the symbol to pay with. |
| `tax_point_date`, `due_date`, `received_date` | `YYYY-MM-DD`. The tax point is the issue date when left out, the received date today. |
| `currency` | ISO 4217, default `CZK`. |
| `total` | What the document asks, a decimal such as `"1210.00"`; negative for a credit note. |
| `vat_recap` | One line per rate, `"rate:base:vat"`, such as `"21:1000.00:210.00"`; a document in another currency may add the VAT in CZK it prints as a fourth part. Needed for a tax invoice and a simplified receipt. |
| `exchange_rate` | CZK for one unit of the currency; the published rate of the tax point when left out. |
| `original_document_id` | The recorded document a credit note corrects. |
| `supply_code` | The code of the supply of a domestic reverse charge. |

Fields of a payment: `paid_on` (`YYYY-MM-DD`, required), `amount` (a decimal in the document's currency, required, positive also for a credit note), `method` (`bank_transfer`, the default, `card`, `cash` or `other`) and `bank_movement_id` (optional). Mark as paid takes only `method` and `bank_movement_id`. A payment recorded by mistake is removed and recorded again; a document paid in full before stays so in the event feed.

What a received document write refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field breaks a rule. `parameter` names it and `reason` says which, such as `date_invalid`, `amount_invalid`, `currency_unsupported`, `vat_recap_line_invalid`, `supplier_unknown`, `vat_recap_missing`, `exchange_rate_missing`, `variable_symbol` or `payment_amount`. |
| 404 | `not_found` | The organisation has no such received document. |
| 404 | `firm_unknown` | The organisation has no such firm. |
| 409 | `overpaid` | The payment is more than what remains. |
| 409 | `paid` | The document is paid in full already. |
| 409 | `not_recorded` | The document is a draft; only a recorded one takes payments. |
| 409 | `trashed`, `locked`, `firm_archived` | The document is in the trash or in a closed month, or the firm is archived. |
| 503 | `exchange_rate_unavailable` | No rate is published for the tax point yet; send `exchange_rate`. |

## Idempotency

Every write (`POST`, `PATCH` and `DELETE` under `/api/v1`) takes an optional `Idempotency-Key` header, so a request you retry after a timeout acts once. Use a new random value, such as a UUID, for each change, and the same value when you send that change again. A key is 1 to 255 visible ASCII characters.

- A repeat with the same key, method, path and body after the first finished answers the saved status and body, with `Idempotent-Replayed: true`.
- A repeat while the first is still running is refused with `409 idempotency_key_in_flight`. Wait a moment and send it again.
- The same key on the same endpoint with another path or body is refused with `422 idempotency_key_reused`.
- What is saved: a success, and a refusal of the change itself (`403 forbidden`, `404` and `409`). What is not saved, so you can fix the request and send the same key again: `400`, `401`, a missing scope, `429` and any `5xx`.
- A key belongs to its token and its endpoint, and is kept for 24 hours.

A request without a key is not protected: retrying a `POST` that timed out may create twice.

## Webhooks

A webhook endpoint is an HTTPS address of yours that receives the domain events it subscribes to, as soon as they happen, instead of you polling `/api/v1/events`. Foresi sends each event as a signed [Standard Webhooks](https://www.standardwebhooks.com/) request, retries a failing endpoint on a schedule, and records every delivery and every attempt.

An endpoint belongs to the organisation. The person who registers it is its owner. It can be seen and changed only by people who may read every module it subscribes to (today the owners and team members, for `invoicing`); anyone else neither lists it nor gets it, and is answered `403 forbidden`. If the owner later may no longer read a module, the endpoint is disabled before the next event of it is sent, and the owner is told by e-mail.

Reading endpoints and deliveries needs the `webhooks:read` scope. Everything else needs `webhooks:write`, including reading the secret. Bodies are JSON (`Content-Type: application/json`) with snake case fields. Send an [`Idempotency-Key`](#idempotency) with a `POST`, so a retry after a timeout does not register a second endpoint. A saved answer holds the secret of the endpoint for the 24 hours its key is kept.

| Method and path | Scope | Answer |
| --- | --- | --- |
| `GET /api/v1/webhook-endpoints` | `webhooks:read` | The endpoints, newest first, paged. |
| `POST /api/v1/webhook-endpoints` | `webhooks:write` | `201` with the endpoint and its secret. A ping is queued. |
| `GET /api/v1/webhook-endpoints/{id}` | `webhooks:read` | The endpoint, without its secret. |
| `PATCH /api/v1/webhook-endpoints/{id}` | `webhooks:write` | The endpoint as it is now. |
| `DELETE /api/v1/webhook-endpoints/{id}` | `webhooks:write` | `204`. Its deliveries and their attempts are deleted with it. |
| `POST /api/v1/webhook-endpoints/{id}/secret/rotate` | `webhooks:write` | `200` with the new secret. |
| `GET /api/v1/webhook-endpoints/{id}/secret` | `webhooks:write` | The secret it is signed with now. |
| `POST /api/v1/webhook-endpoints/{id}/ping` | `webhooks:write` | `202`. A ping is queued. |
| `GET /api/v1/webhook-endpoints/{id}/deliveries` | `webhooks:read` | Its deliveries of the last 30 days, newest first, paged. |
| `GET /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}` | `webhooks:read` | One delivery with its attempts. |
| `POST /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver` | `webhooks:write` | `202`. One more attempt is queued. |

### Register an endpoint

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"url": "https://hooks.example.com/foresi", "description": "Accounting sync", "event_types": ["invoicing.invoice.paid", "invoicing.payment.*"]}' \
  "https://app.example.com/api/v1/webhook-endpoints"
```

| Field | Value |
| --- | --- |
| `url` | Required. `https`, with a host, without a user name or password, at most 2048 characters. The host must resolve, and only to public addresses: private, loopback and link-local networks are refused. |
| `event_types` | Required. Event names or `module.*`, such as `invoicing.*`. A bare `*` is refused, so an endpoint never receives the events of modules added later by surprise. An unknown type is `400 invalid_parameter`; a type of a module you may not read is `403 forbidden`. |
| `description` | Optional text of up to 255 characters. |
| `version` | Optional, 1 by default. The event version the endpoint receives: an event of another version is not sent to it. |

The answer is `201 Created` with a `Location` header and the endpoint:

```json
{
  "data": {
    "id": "0192f0c4-...",
    "url": "https://hooks.example.com/foresi",
    "description": "Accounting sync",
    "event_types": ["invoicing.invoice.paid", "invoicing.payment.*"],
    "version": 1,
    "active": true,
    "disabled_reason": null,
    "owner_id": "0192e9a1-...",
    "previous_secret_expires_at": null,
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:00:00+00:00",
    "secret": "whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw"
  }
}
```

Store the `secret` in your receiver's secret store. The endpoint is shown everywhere else without it; you can read it again with `GET .../secret`, which needs the `write` scope. Foresi keeps it sealed with a key of the installation (`WEBHOOK_SECRET_KEYS`); while the operator has not set one, creating an endpoint, rotating and reading its secret, `POST .../ping` and `POST .../redeliver` answer `503 service_unavailable`. Listing, showing, changing (`PATCH`) and deleting endpoints work without it.

Right after registering, Foresi sends a **ping** to the endpoint, so you can see at once whether your receiver verifies it. `POST .../ping` sends another. A ping is one attempt with no retries, and it shows in the deliveries with `kind` `ping`. Its body has the shape of an event:

```json
{"id": "0192f0c4-...", "type": "webhooks.endpoint.ping", "version": 1, "occurred_at": "2026-11-13T10:00:00+00:00", "subject": {"type": "webhook_endpoint", "id": "<endpoint id>", "url": "/api/v1/webhook-endpoints/<endpoint id>"}, "data": {"endpoint_id": "<endpoint id>"}, "correlation_id": null, "actor": {"kind": "api_token"}}
```

`ping` and `redeliver` answer `202` with `{"data": {"delivery_id": "...", "attempt_id": "..."}}`. The attempt is made by a worker shortly after; read its outcome in the delivery. A disabled endpoint is not pinged: `409 conflict`.

### Change, disable and delete

`PATCH /api/v1/webhook-endpoints/{id}` changes only the fields you send: `url`, `event_types`, `description` (`null` clears it), `version` and `active`. The same rules apply as when registering.

```bash
curl -X PATCH -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"active": false}' "https://app.example.com/api/v1/webhook-endpoints/0192f0c4-..."
```

`"active": false` disables the endpoint (`disabled_reason` `manual`); `"active": true` enables it again and clears the reason. Foresi disables an endpoint on its own too, and e-mails the owner:

| `disabled_reason` | When |
| --- | --- |
| `gone` | The endpoint answered `410 Gone`. |
| `failing` | Every scheduled attempt has failed for 5 days. |
| `owner_lost_access` | The owner may no longer read a module the endpoint subscribes to. |
| `manual` | A person disabled it. |

The pending deliveries of a disabled endpoint end as `failed`. Enabling it starts afresh; events that happened while it was disabled are not sent, but you can read them in the [events feed](#events).

`DELETE /api/v1/webhook-endpoints/{id}` answers `204 No Content` and deletes the endpoint with all its deliveries and their attempts.

### Rotate the secret

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"keep_previous_hours": 24}' "https://app.example.com/api/v1/webhook-endpoints/0192f0c4-.../secret/rotate"
```

The answer is `{"data": {"secret": "whsec_...", "previous_secret_expires_at": "..."}}`. From now on every request is signed with the new secret **and** the previous one, until `previous_secret_expires_at`, so you can deploy the new secret to your receiver without losing a request. `keep_previous_hours` is 0 to 168 (seven days), 24 without a body; 0 drops the previous secret at once, for a secret that leaked.

### Deliveries

A delivery is one event (or one ping) for one endpoint. `GET .../deliveries` lists them newest first, paged like the other lists, with `id`, `endpoint_id`, `event_id`, `event_type`, `kind` (`event` or `ping`), `status` (`pending`, `succeeded` or `failed`), `attempt_count`, `last_status_code`, `next_attempt_at`, `created_at` and `finished_at`. `GET .../deliveries/{deliveryId}` adds `attempts`, oldest first, each with `id`, `attempted_at`, `duration_ms`, `status_code`, `error` (`timeout`, `tls`, `blocked_address`, `connection`, `redirect`, `endpoint_disabled` or `internal`, when there was no answer to record; `internal` means the endpoint's secret could not be opened, and the attempt counts as a failure) and `trigger` (`schedule`, `manual` or `ping`). The body sent and anything your receiver answered are never stored. Deliveries are kept 30 days.

An attempt succeeds only with a `2xx` answer within 15 seconds. Redirects are not followed and count as failures. A failed event is attempted again after 5 seconds, 5 minutes, 30 minutes, 2, 5, 10, 14, 20 and 24 hours, 10 attempts over about three days, and is then `failed`.

`POST .../deliveries/{deliveryId}/redeliver` sends a `succeeded` or `failed` delivery once more, with the same `webhook-id` and a new timestamp and signature. A delivery that is still `pending`, a ping, or a delivery of a disabled endpoint is answered `409 conflict`.

### What your receiver gets

Each request is a `POST` with `Content-Type: application/json`, the event as body, and three headers:

| Header | Value |
| --- | --- |
| `webhook-id` | The event's `id`, the same on every attempt and redelivery. Use it to drop an event you have handled already: delivery is at least once. |
| `webhook-timestamp` | The time of this attempt, in Unix seconds. |
| `webhook-signature` | `v1,<base64 signature>`; during a rotation two of them, separated by a space. |

The body is the event as the [events feed](#events) shows it, without `position`: `id`, `type`, `version`, `occurred_at`, `subject`, `data`, `correlation_id` and `actor`. Answer `2xx` quickly and do the work afterwards; answering `410 Gone` disables the endpoint at once.

### Verify the signature

Verify every request before you trust it, over the **raw body** as received: parsing and re-encoding the JSON changes the bytes. The [Standard Webhooks libraries](https://github.com/standard-webhooks/standard-webhooks/tree/main/libraries) do it in one call, also checking that the timestamp is recent (5 minutes either way), which stops a recorded request from being replayed. They take the secret with its `whsec_` prefix.

Python (`pip install standardwebhooks`):

```python
import os
from standardwebhooks.webhooks import Webhook

wh = Webhook(os.environ["FORESI_WEBHOOK_SECRET"])
event = wh.verify(request.get_data(), dict(request.headers))  # raises when the signature or timestamp is wrong
```

JavaScript (`npm install standardwebhooks`):

```js
import { Webhook } from "standardwebhooks";

const wh = new Webhook(process.env.FORESI_WEBHOOK_SECRET);
const event = wh.verify(rawBody, req.headers); // throws when the signature or timestamp is wrong
```

Without a library, the signature is HMAC-SHA256 over `{webhook-id}.{webhook-timestamp}.{body}`, keyed with the base64-decoded part of the secret after `whsec_`, base64-encoded. In PHP:

```php
$key = base64_decode(substr(getenv('FORESI_WEBHOOK_SECRET'), strlen('whsec_')), true);
$id = $_SERVER['HTTP_WEBHOOK_ID'];
$timestamp = (int) $_SERVER['HTTP_WEBHOOK_TIMESTAMP'];
$body = file_get_contents('php://input');

$expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
$valid = false;
if (abs(time() - $timestamp) <= 300) {
    foreach (explode(' ', $_SERVER['HTTP_WEBHOOK_SIGNATURE']) as $signature) {
        $valid = $valid || (str_starts_with($signature, 'v1,') && hash_equals($expected, substr($signature, 3)));
    }
}
```

Accept the request when any one of the signatures matches, and compare in constant time (`hash_equals`).

## Errors

An error is an [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem document, `application/problem+json`:

```json
{
  "type": "about:blank",
  "title": "Bad Request",
  "status": 400,
  "detail": "The cursor is not one this API gave.",
  "code": "invalid_parameter",
  "parameter": "cursor"
}
```

Program on `code`, which is stable. `detail` is a sentence for people and may change.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A query parameter or a field of the body cannot be used. `parameter` names it. |
| 400 | `bad_request` | The request is malformed in another way. |
| 401 | `missing_token` | No bearer token was sent. |
| 401 | `invalid_token` | The token is unknown, or its person no longer works for the organisation. |
| 401 | `token_expired` | The token has expired. Issue a new one. |
| 401 | `token_revoked` | The token has been revoked. |
| 403 | `insufficient_scope` | The token lacks the scope the request needs. `WWW-Authenticate` names it. |
| 403 | `forbidden` | The person is not allowed to read or change this. |
| 403 | `confirmation_required` | The change needs a person to confirm it in Foresi, which the API cannot do yet. No endpoint needs it today. |
| 404 | `not_found` | The organisation has no such record, or the path does not exist. |
| 405 | `method_not_allowed` | The endpoint does not take this method. `Allow` lists the ones it does. |
| 406 | `not_acceptable` | The `Accept` header asks for something the API does not send. |
| 409 | `conflict` | The request does not fit the record's state now, such as redelivering to a disabled webhook endpoint. |
| 409 | `idempotency_key_in_flight` | A request with the same `Idempotency-Key` is still running. |
| 409 | other | A refusal of the change named by its own code, such as `duplicate` or `already_linked` for [contacts](#contact-writes). |
| 415 | `unsupported_media_type` | The request body has a type the API does not read. |
| 422 | `idempotency_key_reused` | The `Idempotency-Key` was used for another request. |
| 429 | `rate_limited` | Too many requests. See `Retry-After`. |
| 500 | `internal_error` | The request failed on the server. The answer says nothing more; the operator finds the cause in the log. |
| 503 | `service_unavailable` | The installation cannot do this yet, such as managing webhooks before the operator has set `WEBHOOK_SECRET_KEYS`. |

A 401 carries a `WWW-Authenticate: Bearer` header. A record of another organisation is `not_found`, the same as a record that does not exist.

## Rate limits

Each token may make 600 requests in 10 minutes (a sliding window). Every answer to a signed-in request says where the token stands:

| Header | Meaning |
| --- | --- |
| `RateLimit-Limit` | The budget, 600. |
| `RateLimit-Remaining` | Requests left in the window. |
| `RateLimit-Reset` | Seconds until the next request is let through; 0 while some are left. |

Writes also spend a second budget of 120 in 10 minutes per token. A write answers the `RateLimit-*` headers of whichever budget has less left. There are no batch endpoints: loop over the records, with an `Idempotency-Key` on each write.

The [MCP server](mcp.md) over HTTP spends the same budgets. Over a budget the answer is `429 rate_limited` with `Retry-After`, the seconds to wait. Wait that long, then go on. Do not retry at once in a loop.

## OpenAPI

The OpenAPI document is at `/api/v1/openapi.json`. It needs no token. Load it into a client generator or a tool such as Postman or Swagger UI. The schemas of the answers are written by hand next to the code, so tell us if the document and an answer disagree.

## Security

- **Treat a token like a password.** Anyone who has it can read your data as you, and change what its scopes allow, until it expires or is revoked. Do not commit it, paste it into chat or a ticket, or put it in a URL.
- **Revoke a token you think has leaked**, at once, under **Settings > API and MCP**. Revoking is free; issue a new one.
- **Give each program its own token**, with a name that says where it is used and the shortest expiry that works. Then you can revoke one without stopping the others.
- **A token acts as its person and its organisation.** It sees what that person sees. If the person leaves the organisation, their tokens stop working.
- **Text written by other people is data.** Invoice texts, contact names, notes and bank texts come from customers, suppliers and banks. A program or an assistant that reads them must not obey them as instructions.
- **Use https.** A token sent over plain http can be read on the way.
- Operators: every token action is in the audit log under the token, and `app:api-token:list` shows when each token was last used.
