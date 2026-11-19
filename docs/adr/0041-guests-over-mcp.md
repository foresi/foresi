# 41. Guests connect their own agent over MCP

* Status: accepted
* Date: 2026-11-19

## Context and problem statement

A guest is a client a project, section or task is shared with (ADR 0025). Guests had no API access at all: Identity refused them when a personal token was issued, when a token signed a request in, on the OAuth consent screen and at the token endpoint. Since projects and tasks are on the API (ADR 0040), a client can usefully connect their own agent to the work shared with them. Every data module already refuses a person who does not work for the organisation by its own rule, so the refusal in Identity was the only gate; two tools had no rule of their own, `foresi_list_modules` and `foresi_list_lists`.

## Decision drivers

* The API runs the use cases of the screens (ADR 0027): a guest's agent sees and changes what the guest does on the screens, and no more.
* A guest should not be offered, or hold, access that every call would refuse.
* A long-lived secret in the hands of someone outside the organisation is worth avoiding.
* Identity should not have to know which modules a guest may use.

## Decision outcome

### OAuth only

A guest connects an MCP client through OAuth sign-in (ADR 0036), with two-factor sign-in as everyone. The consent screen (`#[OpenToGuest]`), the code exchange, the refresh and the authentication of a request accept a guest; a personal token is still issued to the organisation's people only, and one held by a guest is refused. An OAuth access token is accepted on `/mcp` only, so a guest's agent uses MCP and never the REST API. The guest access guard of the screens lets a request signed in with a token through: from there the scope checks and the modules' rules decide.

### Guest scopes declared by modules

A module opens a resource to guests in its manifest, writing `{"resource": "tasks", "guest": "write"}` in `extra.foresi.scopes` instead of `"tasks"`; `"read"` lets a guest read it only. Work opens `tasks` up to writing and `projects` for reading; `time_entries` stays closed. `ApiResources::guest()` lists them and `GuestScopes` in Identity applies them:

* A guest's consent screen offers only these scopes, and what a guest approves is narrowed to them. `read` and `write` mean every read and every write open to guests.
* A guest's connection is narrowed again whenever it signs a request in, is exchanged or is refreshed. A member who becomes a guest keeps only the guest scopes of their live connections; a connection that keeps none is refused. A refresh can never ask for more than the narrowed scopes.
* `events` and `webhooks` are not open to guests. A guest's client polls.

### What a guest's agent sees

`tools/list` lists what the scopes allow, as before, and leaves out tools that are for the organisation's own people: a tool says so by implementing `StaffOnlyTool`, an operation with `staffOnly: true` in its `#[Operation]`. `foresi_list_modules`, `foresi_list_lists`, `foresi_trash_task` and `foresi_restore_task` are staff-only; calling one as a guest is refused with `forbidden`. `foresi_whoami` stays for everyone, as it returns only the guest's own id, e-mail and token.

The Work reads answer a guest what the guest's screens show: no estimate on a task and no default billability on a project (both `null`), and the people of a project are only the organisation's own, not other guests. An internal comment asked for by a guest is refused with `forbidden` rather than posted for everyone.

### Managing connections

The owner sees and disconnects guests' connections in the existing list under Settings > API and MCP; there is no switch to turn guest connections off. A guest has no settings: their own page, Connected apps, lists the clients they connected, disconnects them and shows the address of the MCP server.

### Consequences

* Removing a guest, or unsharing the work, ends what their agent reaches at its next call, as for the screens.
* A module that opens a resource to guests answers for what its use cases let a guest do; its tests should cover a guest's token.
* A guest's agent has no push: no events feed and no webhooks.
