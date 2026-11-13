# REST API

Foresi has a REST API. Scripts and other systems use it to read the data of an organisation. The decision behind it is in [ADR 0027](adr/0027-api-and-mcp.md). Assistants use the [MCP server](mcp.md), which is built on the same code.

The API lists and shows invoices, contacts and payments, and follows the feed of domain events. The only records it writes are [webhook endpoints](#webhooks): addresses of your own that receive the events as signed requests.

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
- A token issued in Settings can only read (the `read` scope). A token that also writes (the `write` scope), which managing webhooks needs, is issued by the operator from the console for now.
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

`issue` takes `--days` of 30, 90 or 365 (90 by default) and `--scope=read` (the default) or `--scope=write`, which also reads and is needed to manage [webhooks](#webhooks). The person must be the owner or a team member of the organisation. The command prints the token once.

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

Lists are sorted newest first. `limit` is the size of a slice, 25 by default and at most 100. When `next_cursor` is not `null`, send it back as `cursor` to get the next slice, with the same filters. When it is `null`, you have everything (except in the events feed, see below). The cursor is opaque: do not build or change it. A cursor this API did not give is refused with `invalid_parameter`.

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50"

curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50&cursor=MDE5MmYw..."
```

A detail answers `{"data": {...}}`.

## Endpoints

The endpoints below are `GET` and need the `read` scope. Those of webhooks are listed under [Webhooks](#webhooks).

| Endpoint | What it returns |
| --- | --- |
| `/api/v1/me` | The person, the organisation and the token. |
| `/api/v1/invoices` | Sales documents, newest first. |
| `/api/v1/invoices/{id}` | One document with its lines, payments, corrections and balances. |
| `/api/v1/contacts` | Contacts, newest first. |
| `/api/v1/contacts/{id}` | One contact with its addresses, e-mails, bank accounts and contact persons. |
| `/api/v1/payments` | Payments, newest first. |
| `/api/v1/payments/{id}` | One payment with the invoices it pays. |
| `/api/v1/events` | Domain events of the last 30 days, oldest first. |

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

### Events

`GET /api/v1/events` is the feed of what happened in the organisation: an invoice was issued, delivered, paid, trashed or restored, a payment was recorded. Query parameters:

| Parameter | Value |
| --- | --- |
| `types` | Event names or `module.*`, separated by commas, such as `invoicing.invoice.paid,invoicing.payment.recorded`. Without it, every event the token's person may read. An unknown type is `400 invalid_parameter`; a type of a module the person may not read is `403 forbidden`. |
| `limit`, `cursor` | Paging, but the cursor is a position in the feed, and `next_cursor` is not `null` once you have seen an event; see below. |

An item has `id`, `type`, `version`, `position`, `occurred_at`, `subject` (`type`, `id` and the API path `url` to read it), `data`, `correlation_id` and `actor` (`kind`). The `data` holds ids, codes and amounts as text, and no personal data. The feed is in ascending order of `position`, and the events of the last 30 days are kept. To follow it, store the `next_cursor` of the last answer and call again with it as `cursor`: it continues after the last event you got. A page with fewer items than `limit` means you are caught up; call again later with the same `cursor`, and `next_cursor` stays where it was until something new happens. `next_cursor` is `null` only while you have seen no event at all. Delivery to you is at least once, so handle an `id` you have seen before. A type only gains facts within a `version`.

A value an endpoint cannot use, such as a date that is not a date or a `limit` above 100, is answered `400 invalid_parameter`, with the name of the parameter.

## Webhooks

A webhook endpoint is an HTTPS address of yours that receives the domain events it subscribes to, as soon as they happen, instead of you polling `/api/v1/events`. Foresi sends each event as a signed [Standard Webhooks](https://www.standardwebhooks.com/) request, retries a failing endpoint on a schedule, and records every delivery and every attempt.

An endpoint belongs to the organisation. The person who registers it is its owner. It can be seen and changed only by people who may read every module it subscribes to (today the owners and team members, for `invoicing`); anyone else neither lists it nor gets it, and is answered `403 forbidden`. If the owner later may no longer read a module, the endpoint is disabled before the next event of it is sent, and the owner is told by e-mail.

Reading endpoints and deliveries needs the `read` scope. Everything else needs the `write` scope, including reading the secret. Bodies are JSON (`Content-Type: application/json`) with snake case fields. Requests are not idempotent: retrying a `POST` that timed out may register a second endpoint, so list the endpoints before you retry.

| Method and path | Scope | Answer |
| --- | --- | --- |
| `GET /api/v1/webhook-endpoints` | read | The endpoints, newest first, paged. |
| `POST /api/v1/webhook-endpoints` | write | `201` with the endpoint and its secret. A ping is queued. |
| `GET /api/v1/webhook-endpoints/{id}` | read | The endpoint, without its secret. |
| `PATCH /api/v1/webhook-endpoints/{id}` | write | The endpoint as it is now. |
| `DELETE /api/v1/webhook-endpoints/{id}` | write | `204`. Its deliveries and their attempts are deleted with it. |
| `POST /api/v1/webhook-endpoints/{id}/secret/rotate` | write | `200` with the new secret. |
| `GET /api/v1/webhook-endpoints/{id}/secret` | write | The secret it is signed with now. |
| `POST /api/v1/webhook-endpoints/{id}/ping` | write | `202`. A ping is queued. |
| `GET /api/v1/webhook-endpoints/{id}/deliveries` | read | Its deliveries of the last 30 days, newest first, paged. |
| `GET /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}` | read | One delivery with its attempts. |
| `POST /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver` | write | `202`. One more attempt is queued. |

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

Store the `secret` in your receiver's secret store. The endpoint is shown everywhere else without it; you can read it again with `GET .../secret`, which needs the `write` scope. Foresi keeps it sealed with a key of the installation (`WEBHOOK_SECRET_KEYS`); while the operator has not set one, managing endpoints answers `503 service_unavailable`.

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

A delivery is one event (or one ping) for one endpoint. `GET .../deliveries` lists them newest first, paged like the other lists, with `id`, `endpoint_id`, `event_id`, `event_type`, `kind` (`event` or `ping`), `status` (`pending`, `succeeded` or `failed`), `attempt_count`, `last_status_code`, `next_attempt_at`, `created_at` and `finished_at`. `GET .../deliveries/{deliveryId}` adds `attempts`, oldest first, each with `id`, `attempted_at`, `duration_ms`, `status_code`, `error` (`timeout`, `tls`, `blocked_address`, `connection`, `redirect` or `endpoint_disabled`, when there was no answer to record) and `trigger` (`schedule`, `manual` or `ping`). The body sent and anything your receiver answered are never stored. Deliveries are kept 30 days.

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
| 404 | `not_found` | The organisation has no such record, or the path does not exist. |
| 405 | `method_not_allowed` | The endpoint does not take this method. `Allow` lists the ones it does. |
| 406 | `not_acceptable` | The `Accept` header asks for something the API does not send. |
| 409 | `conflict` | The request does not fit the record's state now, such as redelivering to a disabled webhook endpoint. |
| 415 | `unsupported_media_type` | The request body has a type the API does not read. |
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
