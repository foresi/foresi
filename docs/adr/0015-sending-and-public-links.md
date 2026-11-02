# 15. Sending documents and public links

* Status: accepted
* Date: 2026-11-02

## Context and problem statement

An issued invoice has to reach the customer by e-mail, from the firm that issued it, with a visible failure when it does not. The customer reads it without an account. What was sent must stay provable after the invoice changes, and a stored copy of the database must not open any document. Foresi runs on one small host with SQLite or on PostgreSQL, behind any SMTP server a firm already has.

## Decision drivers

* The mail is what the dialog showed: a refusal shows at once, not in a worker.
* A temporary refusal (greylisting) must not lose the mail; a permanent one must not be retried for hours.
* A mail accepted by the server must never go to the customer twice.
* A database leak must not grant access to any document.
* Read state must be honest rather than high: mail filters follow links.
* Tenants type the mail host, so it is a server-side request path.

## Considered options

* Freeze the send in the request, deliver in a worker (chosen)
* Render and send in the worker
* Send synchronously in the request
* PDF attachment instead of, or beside, a link
* A shared sending server of the installation
* Bounce processing through VERP, IMAP or provider webhooks

## Decision outcome

**Frozen in the request.** `SendInvoice` draws the PDF, keeps it under `sent-documents/{tenant}/{sendId}.pdf` with its SHA-256 on the row, and stores the subject, body, recipients, payment details and document figures in one `invoice_send` row, under the lock of the invoice and only if the invoice is still the version drawn. The message is queued after the commit. Rendering in the worker was rejected: an error would surface hours later and the mail could differ from the dialog. Sending in the request was rejected: a slow server would hold the page, and there would be no retries.

**The dialog sends final text.** The dialog shows the firm's template filled in, in the document's language. Only `{link}` stays a token; every other brace is text, so a customer name or a description holding braces is never expanded or refused.

**Delivery.** A `mail` transport on the same database, retries after 1, 4, 16, 64 and 256 minutes, `redeliver_timeout` 600 s. A 5xx answer or a refused login is permanent. A worker claims the send before it talks to the server; acceptance is recorded on the send alone first, with a `Message-ID` fixed as `<{sendId}@{from domain}>`, so a crash in the gap repeats at most once with the same id, and a redelivery of an accepted send sends nothing. Acceptance marks the invoice delivered through its existing method. A failure keeps an English reason for logs, and a code with the SMTP code, which the screen translates.

**Links.** The token is `base64url(HMAC-SHA256(APP_SECRET, "invoice-link|{sendId}|{audience}"))`, 43 characters. The row keeps only `sha256(token)`; the worker derives the token, so no token sits in a queue, a log or the stored text. The firm's own copy has a second token (audience `copy`). Unknown, revoked and cancelled links answer alike. Trash cancels queued sends and revokes every link of the invoice; a delivered invoice cannot be trashed, so `app:invoice:revoke-link` ends one link. Rotating `APP_SECRET` breaks only links of sends still queued.

**Read state.** Only a request the user started (a click on open or download, or one the browser marks user-initiated) counts. The page, the embedded PDF, requests without the browser's mark, the firm's copy and signed-in members never count. No pixel, no IP address.

**Link only.** No PDF attachment and no switch for one: the firm's live setting is a link, and the page lets the customer save the PDF.

**Own server only.** Each firm sends through its own SMTP server, built from DSN objects, encrypted only, with the certificate checked. A host resolving to a private, loopback or link-local address is refused at save and before connecting, unless the operator allows it. A shared fallback server was rejected: a self-hosted product has no sender domain of its own.

**Passwords.** Sealed with `sodium_crypto_secretbox` under keys from `MAIL_SECRET_KEYS`, separate from `APP_SECRET`, with rotation by key id. Symfony Secrets was rejected: they are fixed at deploy time, these are per firm at run time.

**No bounce processing.** A rejection at SMTP time, after retries, is a visible failure. A later bounce reaches the firm's mailbox, as the envelope sender is the firm's address. VERP, IMAP and webhooks were rejected: no general inbound channel exists across SMTP providers.

**Link base.** Links use `DEFAULT_URI`. Sending is refused for a loopback base, and in production for plain `http`.

### Consequences

* Good: the customer gets what the dialog showed; what was sent stays provable; a database leak opens no document; failures are visible in the list, the history, the send log and a notice.
* Bad: the `sent-documents` directory must be backed up with the database. A link sent to a wrong address stays open until an operator revokes it. Read state undercounts on purpose.
* The worker must consume `mail` and `scheduler_foresi`; see [sending.md](../sending.md).
