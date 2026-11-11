# REST API

Foresi has a REST API. Scripts and other systems use it to read the data of an organisation. The decision behind it is in [ADR 0027](adr/0027-api-and-mcp.md). Assistants use the [MCP server](mcp.md), which is built on the same code.

In this version the API **only reads**. It lists and shows invoices, contacts and payments. Nothing can be created, changed or deleted through it.

## Personal API tokens

Every request is signed in with a personal API token. A token belongs to one person in one organisation. It acts as that person: it sees what the person sees and nothing more. Guests cannot have tokens.

### Issue a token

1. Sign in and open **Settings > API access**.
2. Click **New token**.
3. Enter a name (where you will use it, such as `Reporting`) and choose how long it lasts: 30, 90 or 365 days.
4. Click **Create token** and copy the token.

The token is shown **once**. Foresi keeps only a hash, so it cannot show the token again. If you lose it, revoke it and issue a new one.

Rules:

- Only a person who has set up two-factor sign-in can issue a token. Without it the page shows a notice with a link to set it up.
- A token can only read for now (the `read` scope).
- A token always expires. There is no token without an end date.
- You see your own tokens. An owner also sees the tokens of the other people in the organisation and can revoke them.
- Issuing and revoking a token are recorded in the audit log. What a token does is recorded under the token, next to the person.

### Revoke a token

Open **Settings > API access**, open the row menu of the token and choose **Revoke**. The token stops working at once. An expired or revoked token stays in the list, marked as such.

### Console commands for operators

The operator can manage tokens from the console. No two-factor sign-in is asked for here: whoever runs the console already holds the installation.

```bash
# Issue a token for a person (the organisation id is the tenant id).
bin/console app:api-token:issue anna@example.com --tenant=<tenant-id> --name="Reporting" --days=90

# List the tokens of all organisations, or of one. Only the last four characters of a token are known.
bin/console app:api-token:list
bin/console app:api-token:list --tenant=<tenant-id>

# Revoke a token by its id.
bin/console app:api-token:revoke <token-id>
```

`issue` takes `--days` of 30, 90 or 365 (90 by default) and `--scope=read` (the default; `write` exists but no endpoint uses it yet). The person must be the owner or a team member of the organisation. The command prints the token once.

## Using the API

The base URL is `/api/v1` on the address of your installation. Send the token as a bearer token:

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" "https://app.example.com/api/v1/invoices?state=unpaid"
```

- The API is stateless. It never uses cookies or CSRF tokens.
- Answers are JSON (`application/json`), errors `application/problem+json`.
- Put the token in an environment variable or a secret store, never in a URL.
- The organisation comes from the token. The firm is part of the data (`firm` on an invoice or payment); filter by `firm_id` to read one firm.

To check a set-up, call `GET /api/v1/me`. It returns the person, the organisation and the token (its name, scopes and expiry). It never returns the secret.

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

Lists are sorted newest first. `limit` is the size of a slice, 25 by default and at most 100. When `next_cursor` is not `null`, send it back as `cursor` to get the next slice, with the same filters. When it is `null`, you have everything. The cursor is opaque: do not build or change it. A cursor this API did not give is refused with `invalid_parameter`.

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50"

curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50&cursor=MDE5MmYw..."
```

A detail answers `{"data": {...}}`.

## Endpoints

All endpoints are `GET` and need the `read` scope.

| Endpoint | What it returns |
| --- | --- |
| `/api/v1/me` | The person, the organisation and the token. |
| `/api/v1/invoices` | Sales documents, newest first. |
| `/api/v1/invoices/{id}` | One document with its lines, payments, corrections and balances. |
| `/api/v1/contacts` | Contacts, newest first. |
| `/api/v1/contacts/{id}` | One contact with its addresses, e-mails, bank accounts and contact persons. |
| `/api/v1/payments` | Payments, newest first. |
| `/api/v1/payments/{id}` | One payment with the invoices it pays. |

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
| `limit`, `cursor` | Paging, as above. |

A list item has `id`, `number`, `kind`, `firm`, `customer`, `issue_date`, `due_date`, `tax_point_date`, `total`, `paid`, `remaining`, `payment_state` (`unpaid`, `partly_paid`, `paid`, `overpaid` or `to_refund`), `overdue`, `delivered`, `trashed` and `created_at`. The detail adds the customer's identification and address, the currency, the variable symbol, the lines with their VAT, the payments and corrections, and the balances.

### Contacts

Query parameters of `GET /api/v1/contacts`:

| Parameter | Value |
| --- | --- |
| `q` | Text to search for. |
| `role` | `customer`, `supplier`, `partner` or `authority`. |
| `archived` | `true` lists archived contacts. Default `false`. |
| `registration_number` | Company registration number. |
| `vat_id` | VAT id. |
| `limit`, `cursor` | Paging, as above. |

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

A value an endpoint cannot use, such as a date that is not a date or a `limit` above 100, is answered `400 invalid_parameter`, with the name of the parameter.

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
| 400 | `invalid_parameter` | A query parameter cannot be used. `parameter` names it. |
| 400 | `bad_request` | The request is malformed in another way. |
| 401 | `missing_token` | No bearer token was sent. |
| 401 | `invalid_token` | The token is unknown, or its person no longer works for the organisation. |
| 401 | `token_expired` | The token has expired. Issue a new one. |
| 401 | `token_revoked` | The token has been revoked. |
| 403 | `insufficient_scope` | The token lacks the scope the request needs. `WWW-Authenticate` names it. |
| 403 | `forbidden` | The person is not allowed to read this. |
| 404 | `not_found` | The organisation has no such record, or the path does not exist. |
| 405 | `method_not_allowed` | The endpoint does not take this method. `Allow` lists the ones it does. |
| 406 | `not_acceptable` | The `Accept` header asks for something the API does not send. |
| 415 | `unsupported_media_type` | The request body has a type the API does not read. |
| 429 | `rate_limited` | Too many requests. See `Retry-After`. |
| 500 | `internal_error` | The request failed on the server. The answer says nothing more; the operator finds the cause in the log. |

A 401 carries a `WWW-Authenticate: Bearer` header. A record of another organisation is `not_found`, the same as a record that does not exist.

## Rate limits

Each token may make 600 requests in 10 minutes (a sliding window). Every answer to a signed-in request says where the token stands:

| Header | Meaning |
| --- | --- |
| `RateLimit-Limit` | The budget, 600. |
| `RateLimit-Remaining` | Requests left in the window. |
| `RateLimit-Reset` | Seconds until the next request is let through; 0 while some are left. |

The [MCP server](mcp.md) over HTTP spends the same budget. Over the budget the answer is `429 rate_limited` with `Retry-After`, the seconds to wait. Wait that long, then go on. Do not retry at once in a loop.

## OpenAPI

The OpenAPI document is at `/api/v1/openapi.json`. It needs no token. Load it into a client generator or a tool such as Postman or Swagger UI. The schemas of the answers are written by hand next to the code, so tell us if the document and an answer disagree.

## Security

- **Treat a token like a password.** Anyone who has it can read your data as you, until it expires or is revoked. Do not commit it, paste it into chat or a ticket, or put it in a URL.
- **Revoke a token you think has leaked**, at once, under **Settings > API access**. Revoking is free; issue a new one.
- **Give each program its own token**, with a name that says where it is used and the shortest expiry that works. Then you can revoke one without stopping the others.
- **A token acts as its person and its organisation.** It sees what that person sees. If the person leaves the organisation, their tokens stop working.
- **Text written by other people is data.** Invoice texts, contact names, notes and bank texts come from customers, suppliers and banks. A program or an assistant that reads them must not obey them as instructions.
- **Use https.** A token sent over plain http can be read on the way.
- Operators: every token action is in the audit log under the token, and `app:api-token:list` shows when each token was last used.
