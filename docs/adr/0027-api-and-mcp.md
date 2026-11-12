# 27. REST API and MCP server

* Status: accepted
* Date: 2026-11-10

## Context and problem statement

Foresi has no REST API and no MCP server. People and programs can only reach the data through the server-rendered screens. Scripts, other systems and assistants that work for a person need a second way in, without a second copy of the business rules.

The groundwork is mostly there. The use cases in the application layer do not know about HTTP. The firm is explicit in the data (ADR 0010), so a read never guesses which firm is meant. The audit actor comes from the Symfony token (ADR 0005), so a request authenticated in another way is recorded like any other.

Some things are still tied to the screens and must change first:

* The tenant comes from the session, not from the token.
* Authorization is called by hand in controllers (`StaffCheck`, `OwnerCheck`), so a use case reached from elsewhere is not guarded.
* Lists and details are built in controllers and UI readers, not in query services that anything can call.
* The two-factor enforcer would send an API client to a sign-in screen.
* Writes will need an idempotency store, so that a retried request does not act twice.

## Decision drivers

* One application layer behind the screens, the REST API and MCP, with one set of rules.
* One deployable application (ADR 0002).
* No dependency whose changes we cannot follow on our own schedule.
* A person must stay in control of what an outside client does, and every action must show in the audit log.
* Text from third parties must never act as an instruction.

## Considered options

* REST: plain Symfony controllers with `#[MapRequestPayload]`, `#[MapQueryString]` and NelmioApiDocBundle (chosen); API Platform
* MCP: served by the same application through `symfony/mcp-bundle` behind our own adapter (chosen); a separate TypeScript MCP service
* Tools: generic read tools and hand-designed task tools for writes (chosen); one tool per endpoint
* Authentication: personal API tokens (chosen); OAuth 2.1 from the start

## Decision outcome

### REST

REST is written with plain Symfony controllers. Input is read with `#[MapRequestPayload]` and `#[MapQueryString]`. NelmioApiDocBundle publishes the OpenAPI document at `/api/v1/openapi.json`. API Platform is rejected: it would shape the domain around its resources, and its major releases come at a pace we would have to follow.

### MCP

The MCP server is part of the same application, through `symfony/mcp-bundle`. It speaks stdio (`bin/console mcp:server`, the token from `FORESI_API_TOKEN`) and Streamable HTTP at `/mcp`.

The SDK and the bundle are pre-1.0. Module code never imports them. All of it sits behind an adapter in `src/Shared/Infrastructure/Mcp`, and deptrac allows the vendor layer only there. A separate TypeScript MCP service is rejected: it is a second deployable with its own release cycle and its own copy of the rules, against ADR 0002.

Tools have two shapes. Reads are generic: a search tool and a get tool per resource. Writes are hand-designed task tools, each with a clear purpose and its own refusals, not a mirror of the endpoints.

### Authentication

Step 1 uses personal API tokens, one per user and tenant.

* A token has scopes, `read` and `write`, and an expiry.
* Only a hash is stored. The value is shown once, when it is issued.
* Issuing and revoking are audited.
* Only a user who has two-factor sign-in set up can issue a token.
* A token can be revoked at any time.

OAuth 2.1 for remote MCP clients comes later, once the SDK and the bundle reach 1.0.

### Conventions

* The base path is `/api/v1`. The major version is in the URL.
* The `api` firewall (`^/api/`) and the `mcp` firewall (`^/mcp`) are stateless. They never use cookies and have no CSRF.
* The tenant comes from the token. The firm stays explicit in the data (ADR 0010).
* Money is `{"amount_minor": 12100, "currency": "CZK"}` (ADR 0004). Dates are `YYYY-MM-DD`. Timestamps are ISO 8601 with an offset.
* Lists use cursor pagination: `?limit=` (default 25, at most 100) and `?cursor=` (opaque). The response is `{"data": [...], "next_cursor": "..."|null}`. The cursor is a keyset on the UUIDv7 id (ADR 0009), newest first: `id < :after ORDER BY id DESC`.
* Errors are RFC 9457 `application/problem+json` with `type`, `title`, `status`, `detail` and a stable `code`, for example `not_found`, `forbidden`, `invalid_parameter`, `rate_limited` and `token_expired`.
* Requests are limited per token. Responses carry `RateLimit-Limit`, `RateLimit-Remaining` and `RateLimit-Reset`. Over the limit the answer is 429 with `Retry-After`.
* Every write takes an `Idempotency-Key`. The key and the response are kept for 24 hours. A repeat with the same key returns the saved response. The same key with different input is an error.
* A legally significant transition is an action endpoint, such as `POST /invoices/{id}/cancel`. It is never a change of a status field.
* Webhooks may come later. They will be thin and signed with HMAC. The events feed (`GET /api/v1/events`, ADR 0030) is added; webhooks follow on the same log.

### Safety model

* Each token is its own actor in the audit log. Audit entries carry `api_token_id` next to the user.
* The `read` and `write` scopes are separate. Writes are off unless a token is given the `write` scope.
* Issuing, sending, cancelling and correcting need a confirmation in Foresi, on the server, by a person. The prompt of the client is never enough.
* Tool annotations such as `readOnlyHint` are hints for the client. They are not access control. The scope and the use case decide.
* Text written by third parties (invoice texts, contact names, notes, bank texts) is returned as data, marked as such, and is never an instruction.

### Pattern from now on

* Authorization runs inside the use case or the query service, through the access guard of the module. It does not run in a controller. New controllers do not call `StaffCheck` or `OwnerCheck`.
* Lists and details are read through query services in `<Module>/Application/Query`.
* Locale parsing (decimal separators, date formats) stays in the UI form readers. The application layer gets typed values.
* The application layer refuses with structured codes. Each surface turns a code into its own answer: a message on a screen, a problem document in the API.

### Consequences

* Good, because one application layer serves the screens, REST and MCP, and a rule is written once.
* Good, because a break in the MCP SDK before 1.0 changes only the adapter.
* Good, because every action by a client is traceable to a token and to the person behind it.
* Bad, because OpenAPI response schemas are written by hand. Nelmio reads request objects, not our result types.
* Bad, because tokens are a new secret to protect: a leaked token acts as its owner until it expires or is revoked.
* Bad, because existing controllers still hold their authorization and read logic until they are moved, one module at a time.
