# 32. API writes

* Status: accepted
* Date: 2026-11-14

## Context and problem statement

ADR 0027 opened a REST API and an MCP server that read. ADR 0031 added the first writes, the webhook endpoints: each one a hand-written controller, a request object, a tool and two error mappers around one use case. The first module built on the headless core, an outreach campaign, needs an agent that creates and changes contacts. Writing every write four times does not scale to the ten or so operations that module needs, and ADR 0027 promised what the webhook writes still lack: an `Idempotency-Key`, narrower scopes than "read everything" and "write everything", and structured refusals.

## Decision drivers

* An agent retries. A retried create must not create twice.
* A token for one job reaches only what that job needs: an outreach agent touches contacts, never invoices or webhooks.
* One declaration per use case: the REST endpoint and the MCP tool follow from it, with the same checks in the same order.
* The application layer refuses with codes; every surface answers a code the same way.
* Nothing irreversible runs over the API until a person can confirm it on the server.
* The code runs on SQLite in tests and PostgreSQL in production.

## Considered options

* Scope of the change: the write foundation plus contact writes (chosen); plus invoice drafts; plus invoicing with confirmation
* "E-mail sent" and "reply received": in the campaign module, with a core timeline only once a second module needs one (chosen); a core activity timeline now
* Idempotency: a shared store with an optional key (chosen); natural keys only; a required key
* Scopes: per resource, declared in manifests, with `read` and `write` kept as aliases (chosen); `read` and `write` only; per resource plus a delete scope
* Confirmation: a flag that refuses until the mechanism exists (chosen); the mechanism now
* Declaration: an `#[Operation]` attribute on the input of a use case (chosen); hand-written per surface; derived for MCP only
* Contact writes: create, update, archive, restore, link and unlink (chosen); the same plus upsert by a natural key
* Write budget: a second limit per token, no batches (chosen); the shared limit only; a batch create

## Decision outcome

### Scopes

A scope is `read`, `write`, `<resource>:read` or `<resource>:write`. A module declares its resources in its manifest, `extra.foresi.scopes` in its `composer.json`: Party `contacts`, Invoicing `invoices` and `payments`, Webhooks `webhooks`, Events `events`. The registry checks that a resource is a lowercase word declared by one module only.

* `write` grants everything, `read` grants every `:read`, and `<resource>:write` grants `<resource>:read`. A token issued with `read` and `write` before this ADR keeps what it had, and a `write` token now also reads.
* Every endpoint and tool requires one resource scope: the contact endpoints `contacts:read` or `contacts:write`, the webhook writes `webhooks:write`, the events feed `events:read`. `GET /api/v1/me`, `foresi_whoami` and `foresi_list_modules` need a valid token and no scope.
* `tools/list` shows only the tools the token's scopes allow. A call of a hidden tool is refused with `insufficient_scope` as before.
* Settings → API issues tokens with scope checkboxes (read everything, write everything, or per resource), behind the 2FA check that issuing already had. The console requires `--scope` and refuses a scope no installed module declares.

### Idempotency

Every REST write (`POST`, `PATCH`, `DELETE` under `/api/v1`) takes an optional `Idempotency-Key` header, and every MCP write tool an optional `idempotency_key` argument (MCP has no standard for it, so `idempotentHint` stays false). A key is 1 to 255 visible ASCII characters. Keys live in `api_idempotency_key`, unique on tenant, token, operation and key; the operation is the surface plus the route or tool name, so a key never crosses tokens, organisations or surfaces. The row holds a SHA-256 fingerprint of the input: method, path and body over REST, the arguments without the key over MCP.

* The first request inserts the row as in flight before the controller or tool runs. The unique index is the lock.
* A repeat with the same input while the first is in flight is refused with `409 idempotency_key_in_flight`. A row in flight for more than a minute is taken over, as its request died.
* A repeat with a different input is refused with `422 idempotency_key_reused`.
* A repeat with the same input after the first finished answers the saved status and body with `Idempotent-Replayed: true` (over MCP, `idempotent_replayed: true` in the structured content).
* What is saved: a success and a refusal of the application layer once the use case ran (403 `forbidden`, 404, 409). What is not saved, so the key can be sent again: an invalid input (400), a missing or wrong token, a missing scope, a refused confirmation, the rate limit and any 5xx.
* Keys are kept 24 hours and pruned by an operator task once an hour.

This follows draft-ietf-httpapi-idempotency-key-header-08 and Stripe's v1 behaviour. A saved body can hold a secret shown once (a new webhook endpoint's); it is kept 24 hours, answered only to the token that asked for it, and never logged.

### Operations

A write is declared once, by `#[Operation]` on the input class of its use case (`App\Shared\Application\Api\Operation`): a name such as `contacts.create`, the REST method and path, the MCP tool name, the scope, a title and a description written for agents, the success status, `destructive` and `needsConfirmation`. Each constructor parameter of the input is a field; `#[Field]` gives it a description, a maximum length or a list of allowed values. Path parameters such as `{contactId}` are fields too, `contact_id` over the wire. A field of a partial update defaults to `Omitted`, so a field left out is told apart from `null`, which clears it.

An `OperationHandler` in the module's infrastructure (tag `app.operation_handler`) names its input class, calls the use case and returns the view the read endpoints answer. From the declarations:

* `OperationRoutes` adds a route per operation to the generic `OperationController`, and `OperationDocs` adds each to the OpenAPI document with its body schema.
* The MCP loader registers a tool per operation with a JSON Schema derived from the input (`additionalProperties: false`, plus `idempotency_key`) and the annotations `readOnlyHint: false`, `destructiveHint` from the declaration, `idempotentHint: false`.
* Both surfaces run the same checks. Over MCP the order is the scope, the write budget, idempotency, the confirmation flag, the input (types and allowed values; a wrong one is `invalid_argument` naming the field), then the handler. Over REST the write budget is spent and the key claimed before the controller, and `OperationRunner` then checks the scope, the confirmation flag and the input (400 `invalid_parameter` naming the field), then calls the handler. A refusal before the handler is never saved under the key, so the order differs only in which refusal comes first.
* A contract test checks that every operation has a route and a tool and that names, tools and routes are unique.

Reads, webhooks and the `whoami` style tools stay hand-written and can move later.

### Refusals

A use case refuses with an exception that implements `App\Shared\Application\Refused`: a stable reason code, a kind, the field it is about and details. The kind decides the answer on both surfaces:

| Kind | REST | MCP code |
|---|---|---|
| invalid | 400 `invalid_parameter` with `parameter` and `reason` | `invalid_argument` |
| forbidden | 403, code = reason | reason |
| not found | 404, code = reason | reason |
| conflict | 409, code = reason, details as members | reason, details in `structuredContent.error` |
| unavailable | 503, code = reason | reason |

Party refuses with `PartyRefused`, whose reasons are a closed list (`name_required`, `email_invalid`, `company_identifier_required`, `duplicate`, `person_invalid`, and so on). `duplicate` carries `existing_ids`.

### Confirmation

An operation declared with `needsConfirmation: true` is refused before it runs, with 403 `confirmation_required` over REST and the same code over MCP, and the refusal is not saved under a key. No operation is declared so yet. The mechanism (a person approves an exact payload in Foresi, the agent retries with the approval) comes with the invoicing writes, which are the first to need it.

### Contacts

Party moves what the screens checked into its application layer:

* The write use cases ask `PartyAccess::denyUnlessMayChange()`: the owners and members of the organisation. Work without a signed-in person (an import worker, the console) is the application's own and passes; no route reaches these use cases without a sign-in, as the firewalls keep every screen and API route behind one.
* `PartyRules` holds the lengths, the e-mail check, "a company needs a registration number or VAT id" and the kinds a link needs. The form reader and the link screen read through it and keep their messages through a map from reason to translation key.
* `UpdateContact` changes only the fields given; `UnlinkContactPerson` removes a link.
* Party records `party.contact.created`, `party.contact.updated`, `party.contact.archived` and `party.contact.restored`, version 1, with the contact id and kind only, in the transaction of the change.

The operations, all with `contacts:write` and the contact detail as the answer:

| Operation | REST | MCP |
|---|---|---|
| `contacts.create` | `POST /api/v1/contacts`, 201 | `foresi_create_contact` |
| `contacts.update` | `PATCH /api/v1/contacts/{contactId}` | `foresi_update_contact` |
| `contacts.archive` | `POST /api/v1/contacts/{contactId}/archive` | `foresi_archive_contact` |
| `contacts.restore` | `POST /api/v1/contacts/{contactId}/restore` | `foresi_restore_contact` |
| `contacts.link_person` | `POST /api/v1/contacts/{contactId}/contact-persons` | `foresi_link_contact_person` |
| `contacts.unlink_person` | `DELETE /api/v1/contacts/{contactId}/contact-persons/{personId}` | `foresi_unlink_contact_person` |

Create refuses a registration number or VAT id the organisation has already with 409 `duplicate` and the ids it matched; the agent then finds the contact (the search covers e-mails) and updates it. There is no upsert: e-mail is not a unique key in the address book, and a silent merge is worse for an agent than a refusal.

### Write budget

Writes count against a second budget of 120 per 10 minutes per token (`api_token_write`), on top of the shared 600. A REST write answers the `RateLimit-*` headers of whichever budget has less left; over it the answer is 429 `rate_limited` with `Retry-After`, and an MCP write tool refuses with `rate_limited`. There are no batch endpoints: the agent loops, and idempotency makes retries safe.

### Consequences

* Good, because a new write is an input class, a use case and a small handler, and the REST route, the tool, the schema, the scope check and idempotency follow.
* Good, because a retried write with a key acts once, on both surfaces.
* Good, because a token can be narrowed to one resource and its tools list shows only what it may call.
* Good, because the screens, REST and MCP refuse a contact alike, with the same codes.
* Bad, because the derived JSON Schema knows only what the constructor and `#[Field]` say; richer rules stay in the use case and are refused there.
* Bad, because the webhook writes and the reads are still written by hand and differ in shape from the operations until they move.
* Bad, because a body saved under a key can hold a webhook secret for 24 hours.
* Bad, because the write budget over MCP has no headers: a tool learns of it only when refused.

ADR 0027 is updated: write implies read, scopes are per resource, and idempotency is implemented and optional.
