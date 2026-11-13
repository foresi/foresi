# 31. Outbound webhooks

* Status: accepted
* Date: 2026-11-13

## Context and problem statement

ADR 0030 records domain events in `event_log`, numbers them in a relay and serves them as a feed. A system that wants to react without polling has nothing to subscribe to. The relay already has a place where each numbered event passes in one transaction, so webhooks are a second reader of the same log, not a new source of events.

## Decision drivers

* A receiver can verify who sent a request, and a standard library can do it: the Standard Webhooks specification (https://www.standardwebhooks.com).
* A slow or hostile receiver holds up no one else, and cannot make the server reach into its own network (OWASP, Server-Side Request Forgery).
* A delivery is stored with its events: no event is delivered for a change that is not committed, and none is lost on a crash.
* The signing secret is never stored in plain text and is shown to its owner only on request.
* A person receives only what they could read in the application.
* The code runs on SQLite in tests and PostgreSQL in production.

## Considered options

* Where deliveries start: a sink called by the relay in its transaction (chosen); a Messenger listener per event; a second reader of the feed
* Queue: its own `webhooks` transport, one message per attempt (chosen); the shared `async` transport
* Schedule: kept in the delivery row, each attempt dispatched with a delay (chosen); Messenger retry strategy
* Secret: sealed with sodium under keys of its own, `whsec_` format (chosen); plain column; derived from the application secret

## Decision outcome

### The module

`Webhooks` (`src/Webhooks`, id `webhooks`) requires shared, tenancy, audit, events, identity and ui. It stores `webhook_endpoint` (Doctrine entity, audited, apart from `failing_since`) and, in raw DBAL, `webhook_delivery` and `webhook_delivery_attempt`. An endpoint has a URL, an optional description, event patterns (`module.resource.action` or `module.*`), a pinned event version (a whole number, default 1), the sealed secret, an owner and whether it is active. The creator is the owner. Endpoints are managed by people who may read the data of every module they subscribe to, with the `write` scope for REST and MCP.

### Request

A delivery is a `POST` of the envelope of ADR 0030 without `position`. The JSON is encoded once, when the delivery is created, and stored in `webhook_delivery.body`, so every attempt signs and sends the same bytes. Headers follow Standard Webhooks:

* `webhook-id`: the event id, the same for every attempt, so a receiver deduplicates on it;
* `webhook-timestamp`: Unix seconds at the time of the attempt;
* `webhook-signature`: `v1,<base64 HMAC-SHA256>` over `<id>.<timestamp>.<body>`; with a rotation in progress, two signatures separated by a space, the current secret first.

The secret is `whsec_` plus base64 of 32 random bytes; a secret given by the caller must decode to 24 to 64 bytes. The client sends `Content-Type: application/json` and `User-Agent: Foresi-Webhooks/1`.

### Fan-out

`WebhookFanout` is an event sink, tagged `app.event_sink`; the relay calls every sink for each event it numbers, inside its transaction (ADR 0030). For each active endpoint of the event's organisation that subscribes to the name at the event's version, the sink inserts a pending delivery and dispatches `SendWebhook` on the `webhooks` transport. The Doctrine transport shares the connection, so positions, deliveries and messages are stored together or not at all. The sink makes no request. The relay resets sinks that implement `ResetInterface` after each batch, which clears the sink's per-batch memo of endpoints and owners.

Endpoints of an organisation are read once per batch. A row that cannot be read (a column of an unexpected type) is skipped and logged, so one bad row holds up nobody's events.

### Readability

An endpoint is saved only by a person who may read the events of every pattern it subscribes to, and the same rule is checked again at fan-out, for the owner and the module of the event. An owner who may no longer read the module gets nothing more: the endpoint is deactivated with reason `owner_lost_access` by a plain DBAL update in the relay's transaction (it leaves no audit entry) and the owner is told by e-mail. The check is for the event's module only; an endpoint that subscribes to several modules is caught at the first event of the module the owner lost.

### Attempts and the schedule

`SendWebhookHandler` makes one attempt. It first claims the delivery:

* a scheduled attempt moves `next_attempt_at` 120 seconds ahead as a lease, one changed row meaning this worker holds the delivery, so two messages for one attempt send once;
* a manual attempt or a ping claims by inserting its attempt row, which carries an id named by the message.

The request is made outside any transaction. The result is recorded in a second one: the attempt row (status, duration, error code) and what follows from it. A client of its own (`WebhookHttpClientFactory`) makes the request with a 15 second timeout and maximum duration and no redirects.

* Only a 2xx response succeeds. Any 2xx ends the delivery as succeeded and clears the endpoint's `failing_since`, whichever attempt made it.
* A 3xx response is not followed: the attempt fails with error `redirect`.
* `410 Gone` deactivates the endpoint at once (reason `gone`) and tells the owner.
* Other failures record an error code (`timeout`, `tls`, `blocked_address`, `connection`, `redirect`) or the status. The codes `timeout`, `tls` and `blocked_address` are told apart from the client's exception messages, because Symfony has no typed exceptions for them.

The schedule has ten attempts, at 0 s, 5 s, 5 min, 30 min, 2 h, 5 h, 10 h, 14 h, 20 h and 24 h after the previous one (`RetrySchedule`, as in the Standard Webhooks recommendation). Delays of 5 minutes and more get a jitter of up to 10 % in either direction. The next attempt is dispatched with a `DelayStamp` and stored in `next_attempt_at`; Messenger itself never retries on this transport. After the tenth failure the delivery is `failed`.

A task on the schedule (`webhook-sweep`) queues again the pending deliveries that are more than a minute overdue, which covers a lost message and a worker that died after the claim, whose lease then ran out. Pings are never swept.

### Disabling

A scheduled failure sets `failing_since` on the endpoint if it is empty. When the failures have run for 5 days with no success in between, the endpoint is deactivated (reason `failing`) and its owner is told by e-mail through the system mailer, with a link to the endpoint. A re-enabled endpoint starts again. The mail is queued in the same transaction as the change.

### Pings and manual redelivery

Creating an endpoint queues a ping, a delivery of kind `ping` with the type `webhooks.endpoint.ping` and the same envelope, so a receiver can verify its setup. `POST .../ping` sends another. A finished delivery of an event can be redelivered by hand: one more attempt with the same `webhook-id` and a new signature. A ping and a manual redelivery are one attempt each: they are never retried, their failures do not count towards the 5 days, and a failed redelivery leaves the delivery as it was. Ping and redelivery answer `409` when the endpoint is disabled, when the delivery is a ping and when it is still pending, and `503` when no secret key is configured.

### The transport

Attempts run on the `webhooks` Messenger transport (Doctrine, own queue, `redeliver_timeout: 600`), routed by `SendWebhook`, so a slow receiver holds up this queue only. Every message carries a tenant stamp. In tests the transport is synchronous.

### Secrets

The secret is sealed with the shared sodium sealer under the keys of `WEBHOOK_SECRET_KEYS` (same format and rotation as `MAIL_SECRET_KEYS`, but separate). Without a key no endpoint can be saved. The plain secret leaves the application on creation, on rotation and on an explicit `GET .../secret`, which needs the `write` scope, and in the settings screen behind a confirmation. It is never in the audit log or in a list.

Rotation creates a new secret and keeps the previous one for 24 hours by default (0 to 168), signing with both, so a receiver can switch without downtime. A previous secret that would expire at once is dropped.

### The SSRF client

A URL must be `https` (`http` is refused), without credentials and at most 2048 characters. `DnsWebhookUrlPolicy` resolves the host when the endpoint is saved. The request is made by Symfony's `NoPrivateNetworkHttpClient` around a client with `max_redirects: 0`, so the address is checked again when connecting and a private, loopback or link-local address is refused with `blocked_address`. `app.webhooks.allow_private_hosts` (false by default) lifts the private-network checks for an installation whose receivers are inside its network.

### Surfaces

REST: `GET|POST /api/v1/webhook-endpoints`, `GET|PATCH|DELETE .../{id}`, `POST .../{id}/secret/rotate`, `GET .../{id}/secret`, `POST .../{id}/ping`, `GET .../{id}/deliveries`, `GET .../{id}/deliveries/{deliveryId}` and `POST .../redeliver`. MCP: `foresi_list_webhook_endpoints`, `foresi_list_webhook_deliveries` and the write tools `foresi_create_webhook_endpoint`, `foresi_update_webhook_endpoint`, `foresi_delete_webhook_endpoint`, `foresi_rotate_webhook_secret` and `foresi_redeliver_webhook`. Settings screens list the endpoints and their deliveries. All three go through `ManageWebhookEndpoints`.

### Retention

A task on the schedule deletes deliveries and their attempts older than 30 days once a day. A delivery waiting for an attempt is never deleted.

### Consequences

* Good, because one log serves listeners, the feed and webhooks, and a delivery is stored in the transaction that numbers its event.
* Good, because signing follows Standard Webhooks, so receivers use the standard libraries.
* Good, because a slow or hostile receiver holds up only its own queue and cannot reach the private network.
* Good, because every attempt is stored with its status, error and duration, and can be redelivered.
* Bad, because there is no ordering between events: positions are the feed's, attempts are concurrent and a retry arrives after later events. A receiver orders by `occurred_at` and the subject, and deduplicates on `webhook-id`.
* Bad, because `Retry-After` of a response is not honoured yet; the schedule is fixed.
* Bad, because there is no bulk "recover since" for an endpoint that was down longer than the retention or the schedule; the feed can be read from a cursor meanwhile.
* Bad, because the `Idempotency-Key` header, which some receivers expect, is not sent; `webhook-id` serves the purpose, and the question stays open.
* Bad, because the checks on the owner at fan-out run in the relay and switch the active tenant for a moment, and a disabling by `owner_lost_access` is not in the audit log.
* Bad, because the secrets of endpoints are lost with `WEBHOOK_SECRET_KEYS`: every endpoint then needs a new secret.
