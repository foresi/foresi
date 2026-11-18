# 36. OAuth 2.1 sign-in for MCP clients

* Status: accepted
* Date: 2026-11-18
* Supersedes: the deferral of OAuth in [ADR 0027](0027-api-and-mcp.md) ("OAuth 2.1 for remote MCP clients comes later, once the SDK and the bundle reach 1.0")

## Context and problem statement

An MCP client signs in to Foresi with a personal API token that a person issues by hand in Settings > API and MCP and pastes into the client (ADR 0027, ADR 0032). The common MCP clients (Claude on the web, on the desktop and in Claude Code, Cursor, VS Code) expect the other way of the MCP specification: they get a 401 from the server, discover its authorization server, send the person to a sign-in and consent page and receive tokens themselves. claude.ai can only connect a remote server that way or with no sign-in at all.

The MCP specification (revision 2026-07-28) makes authorization optional, but when it is offered it is OAuth 2.1 with PKCE `S256`. The MCP server publishes protected resource metadata (RFC 9728) and names it in the `WWW-Authenticate` header of the 401. The authorization server publishes its metadata (RFC 8414). The client sends the `resource` parameter (RFC 8707), and the server checks the audience. Client ID metadata documents (CIMD) are recommended, dynamic client registration (RFC 7591) is allowed.

The PHP SDK (`mcp/sdk` 0.9) is a resource server only: it validates tokens but does not issue them. `symfony/mcp-bundle` 0.15 has no authorization server. `league/oauth2-server` covers the code and refresh grants, but neither CIMD, RFC 7591, RFC 8414, RFC 9728 nor RFC 8707.

## Decision drivers

* A person connects Claude, Cursor or VS Code without copying a token, and still chooses what the client may do.
* No external service: a self-hosted installation stays one application (ADR 0002) with no identity provider to run.
* One access model: what an OAuth client gets is the same scoped, revocable token the firewall, the scope checks, the rate limit and the audit log already know.
* Every secret is stored only as a hash.
* Security-critical rules (PKCE, redirect matching, rotation, fetching documents from the internet) are few, written once and tested on their failure paths.

## Considered options

* A. Foresi's own minimal authorization server (chosen)
* B. `league/oauth2-server-bundle` for the grants, our own code for everything else
* C. Wait for the SDK and the bundle, keep hand-made tokens
* D. An external authorization server (Keycloak, Authentik, Ory Hydra) and the SDK's JWT validator

## Decision outcome

Chosen option A. Foresi serves the authorization server itself, in the Identity module. The OAuth access token is an ordinary `ApiToken` row linked to the OAuth client, so nothing behind the firewall changes. Hand-made tokens stay for scripts and for clients without OAuth.

### Endpoints

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/oauth-protected-resource/mcp` (and without `/mcp`) | Protected resource metadata (RFC 9728). `resource` is the absolute URL of `/mcp`, `authorization_servers` is this installation. |
| `GET /.well-known/oauth-authorization-server` | Authorization server metadata (RFC 8414): `S256` only, `client_id_metadata_document_supported: true`, `token_endpoint_auth_methods_supported: ["none"]`, the registration endpoint, `authorization_response_iss_parameter_supported: true`. |
| `POST /oauth/register` | Dynamic client registration (RFC 7591) for clients without CIMD. Public clients only, limited per address. |
| `GET` and `POST /oauth/authorize` | The consent screen, behind the ordinary sign-in with two-factor authentication. |
| `POST /oauth/token` | The `authorization_code` and `refresh_token` grants, form-encoded. |

The 401 of `/mcp` carries `WWW-Authenticate: Bearer resource_metadata="…/.well-known/oauth-protected-resource/mcp"`.

### Clients

* **CIMD first.** A `client_id` that is an `https` URL is a client ID metadata document. Foresi fetches it, checks that its `client_id` is that URL, that it lists `redirect_uris` and that it asks for no client secret, and keeps a copy for a day. The fetch only goes to `https` on public addresses (every resolved address is checked, private, loopback and link-local ones are refused), follows no redirects, stops after 5 seconds and refuses documents over 5 kB.
* **DCR as the fallback.** A registered client gets a random `client_id`. It is a public client: no secret is issued, `token_endpoint_auth_method` is `none`.
* **Redirect URIs match exactly**, with one exception from RFC 8252: a loopback redirect (`http://localhost`, `http://127.0.0.1`, `http://[::1]`) matches on any port, as native clients such as Claude Code pick a free port. Other `http` redirects are refused. A private-use scheme such as `cursor://` is allowed.

### Consent

`/oauth/authorize` checks the request before it shows anything: a known client, an exact redirect URI, `response_type=code`, a `code_challenge` with method `S256`, and a `resource` that is the MCP URL. A wrong client or redirect URI is shown as an error page and never redirected to. Other errors go back to the client as `error=` on its redirect URI.

The person signs in as usual, two-factor step included; who has not set up two-factor sign-in cannot connect a client, as with tokens (ADR 0027). The screen shows the client's name, where it sends the person back (the redirect host) and whether the name was verified (CIMD, by its domain) or only stated (DCR). The scopes are the checkboxes of the token form. What the client asked for is ticked. Read access the client asked for is required and stays ticked; anything else can be unticked or ticked (the Cloudflare pattern, see sources). Approving writes an authorization code that is valid for 5 minutes and usable once.

### Tokens

* The access token is an `ApiToken` row with the prefix `foresi_oat_`, valid for an hour, carrying the granted scopes and the OAuth client. One row stands for one connection: a refresh puts a new secret into the same row, so the list of connected clients, the revoke button and the audit log show connections, not hourly tokens.
* OAuth access tokens are accepted on `/mcp` only, the resource they were issued for (RFC 8707). The REST API keeps taking personal tokens.
* The refresh token (`foresi_ort_`) is valid for 30 days and rotates on every use. A used refresh token presented again revokes the connection (reuse detection); an unknown, expired or revoked one gets `invalid_grant`.
* An authorization code presented twice gets `invalid_grant` and revokes what the first exchange issued (RFC 6749, 4.1.2).
* Codes, access tokens and refresh tokens are stored only as SHA-256 hashes. 32 random bytes need no slow hash.

### Resource server

The Symfony `mcp` firewall stays the check of the bearer token, for personal and OAuth tokens alike. The SDK's `AuthorizationTokenValidatorInterface` and `AuthorizationMiddleware` are not used: the firewall already runs before the bundle, and module code never imports the SDK (ADR 0027). Foresi serves the protected resource metadata itself.

### Consequences

* Good: Claude, Cursor and VS Code connect with a sign-in and a consent screen; claude.ai can add Foresi as a custom connector.
* Good: revoking, scopes, rate limits and the audit log work for OAuth clients unchanged.
* Bad: we own security-critical code. It is limited to public clients, `S256`, exact redirects and the two grants, and every failure path has a test.
* Bad: anyone can register a client by DCR. Registration only stores a name and redirect URIs; nothing happens without a person's consent, and the consent screen says the name is unverified.

## Pros and cons of the other options

### B. league/oauth2-server-bundle

* Good: a vetted core for PKCE, codes and refresh tokens.
* Bad: CIMD, DCR, both metadata documents, the resource check and the consent screen are still ours, and its entities would have to be mapped onto `ApiToken`. A new dependency for the smaller half of the work.

### C. Wait

* Good: no work now.
* Bad: claude.ai cannot connect at all, and everyone else keeps copying tokens. The SDK has no plans for an authorization server.

### D. External authorization server

* Good: a mature implementation of everything above.
* Bad: every self-hosted installation would run and secure a second service, and the people and their two-factor sign-in would live in two places. Rejected.

## Sources

* MCP authorization, revision 2026-07-28: https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization
* Claude connectors, authentication: https://claude.com/docs/connectors/building/authentication.md
* OAuth 2.1 draft, RFC 7591, RFC 8252 (7.3, loopback ports), RFC 8414, RFC 8707, RFC 9207, RFC 9728
* OAuth Client ID Metadata Document draft: https://datatracker.ietf.org/doc/draft-ietf-oauth-client-id-metadata-document/
* Cloudflare, task-based consent with optional scopes: https://blog.cloudflare.com/task-based-oauth-consent/
* Cloudflare `workers-oauth-provider`, tokens stored as hashes: https://github.com/cloudflare/workers-oauth-provider
* Stripe MCP, OAuth sessions revoked per client: https://docs.stripe.com/mcp
