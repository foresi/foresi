# Sending documents by e-mail

Foresi sends an invoice as a link to its PDF, through the firm's own mail server. The customer gets a short mail with the link; the link opens a page with the document, its payment details and the PDF. This page describes how to set sending up, what it keeps, and what its states mean.

## What happens when you send

1. The send dialog opens over the page it starts from: the invoice list, the history of an invoice, or the invoice form after "Send and save". It shows the recipients and the message at a glance, with Send ready for one click or Enter; "Edit text" opens the subject, the text and other recipients in the same dialog. Everything is filled in from the firm's template in the document's language. Without JavaScript the same address is a page of its own. `{Link to the document}` marks where the link goes; it is the only placeholder left, and any other brace in the text is sent as written.
2. On Send, Foresi draws the PDF and keeps it, together with the subject, the text, the recipients and the payment details, exactly as they will be sent. A later edit of the invoice changes none of this.
3. The send waits in the `mail` queue. A worker hands it to the firm's mail server. A temporary refusal is retried after 1, 4, 16, 64 and 256 minutes (about 5.7 hours, which outlasts greylisting); a permanent refusal (a 5xx answer, a refused login) fails at once.
4. The invoice list marks each invoice as sending, sent, read or failed. The invoice's history shows every send with its text and the PDF as it was sent, and **Sales › Sent e-mails** lists all sends with a filter by firm and state.

"Copy to me" sends a second message, with a link of its own, to the firm's contact e-mail (or the signed-in user's), so opening your own copy never marks the customer's link as read.

## Setting up the mail server

Each firm sends through its own SMTP server. The owner sets it under **My firms › (firm) › E-mails › Mail server**: host, port, encryption (STARTTLS on port 587, or TLS on 465), user name, password, sender address and name. "Send a test e-mail" sends one message to the signed-in owner. There is no shared fallback server: a firm without a server cannot send, and the dialog says where to set it.

- The connection is always encrypted and the certificate is always checked. There is no unencrypted mode.
- A host that resolves to a private, loopback or link-local address is refused, when saving and again before connecting, unless the operator sets `app.mail.allow_private_hosts: true` (for a mail relay inside the operator's own network).
- `From` is the server's sender address. `Reply-To` is the firm's contact e-mail when it has one, so replies reach the firm.

### Passwords: `MAIL_SECRET_KEYS`

Mail server passwords are sealed with `sodium_crypto_secretbox` before they are stored. The keys come from the environment variable `MAIL_SECRET_KEYS`, never from the database and never from `APP_SECRET`, so a copy of the database alone does not reveal them.

Generate a key (32 random bytes, base64):

```bash
php -r 'echo base64_encode(random_bytes(32)), "\n";'
```

Set it outside committed files, for example in `.env.local` or the service environment:

```
MAIL_SECRET_KEYS=k1:BASE64KEY
```

The value is a comma-separated list of `id:key`. The first key seals new passwords; the others only open what they sealed. To rotate, put a new key first (`k2:NEWKEY,k1:OLDKEY`), ask each firm owner to save the mail server password again, and remove the old key once no stored password uses it. Without any key no firm can save a mail server. A password that no configured key opens fails the send with "The mail server password cannot be opened with the configured keys"; the owner enters it again.

### SPF, DKIM and DMARC

Foresi does not sign mail and does not choose the route a mail takes: the firm's SMTP provider does. Whether the customer's mail server accepts the mail depends on the DNS records of the sender's domain. For a sender address `invoices@example.com` sent through a provider:

- **SPF**: the domain's SPF record includes the provider's servers, as the provider documents it, for example
  `example.com. TXT "v=spf1 include:_spf.mailprovider.example ~all"`.
- **DKIM**: the provider signs the mail. Add the DKIM record the provider gives you, for example
  `selector1._domainkey.example.com. TXT "v=DKIM1; k=rsa; p=MIIB..."`.
  Foresi has no DKIM key of its own and needs none.
- **DMARC**: the domain of the `From` address must align with the SPF or DKIM domain. Start with monitoring, for example
  `_dmarc.example.com. TXT "v=DMARC1; p=none; rua=mailto:dmarc@example.com"`,
  and tighten the policy once the reports are clean.

The sender address in the mail server settings must be one the provider allows for that account.

### The link base: `DEFAULT_URI`

Links in sent mail are built from `DEFAULT_URI`, the public address of the installation, for example `DEFAULT_URI=https://invoices.example.com`. Sending is refused when it points to `localhost` or a loopback address (the customer could not open it) and, in production, when it is plain `http`.

## The running worker

Sending needs the background worker, which must consume the scheduler and mail transports beside the others:

```bash
php bin/console messenger:consume scheduler_foresi mail async registry --memory-limit=256M --time-limit=3600
```

- `scheduler_foresi` ticks every five minutes and runs the timed jobs of each organisation, such as handing back sends whose queue message was lost. Without it, a send whose web request died after storing it is never retried.
- `mail` delivers the sends. Without it nothing leaves; after 15 minutes the application shows a notice that sends are waiting.
- The schedule's state is kept in the application cache and its lock in `LOCK_DSN`. The default file lock (`flock`) suits one host; with several hosts set a shared lock store, as for imports (see [import.md](import.md)).

See [import.md](import.md#running-the-worker) for systemd and supervisor units.

## The public link

The link is `https://<DEFAULT_URI>/d/<token>`. The token has 256 bits; the database keeps only its SHA-256 hash, so a copy of the database cannot open links. The page and the PDF need no sign-in, start no session, run no script and set `X-Robots-Tag: noindex, nofollow`, `Referrer-Policy: no-referrer` and `Cache-Control: private, no-store`. An unknown, revoked or cancelled link gets the same answer.

The link page is limited to 60 requests a minute per client address. Behind a reverse proxy or a load balancer, configure Symfony's trusted proxies (`SYMFONY_TRUSTED_PROXIES` or `framework.trusted_proxies`, with `trusted_headers`), or every customer shares the proxy's address and one busy hour blocks them all.

A link keeps showing the PDF that was sent, also after the invoice is edited; the invoice form warns that the invoice changed since it was sent, so you can send it again. Trash cancels sends that have not gone out and revokes their links. A delivered invoice cannot be trashed, so to end a link that went to a wrong address, the operator runs:

```bash
php bin/console app:invoice:revoke-link <send-id>
```

The send id is in the address of the "PDF as sent" link on the invoice's history.

## What the states mean

| State | Meaning | What it does not mean |
|---|---|---|
| Sending | Stored and waiting for the worker, or being retried after a temporary refusal | That the worker runs |
| Sent | The firm's mail server accepted the message. The invoice is then marked delivered | That it reached an inbox. A later bounce goes to the firm's own mailbox, as the envelope sender is the firm's address; Foresi does not read it |
| Read | Someone opened or downloaded the PDF from the customer's link, by a click | That the customer read it. Opening the page, a mail filter following the link, the firm's own copy and a signed-in member of the firm never count |
| Failed | The server refused the message for good, or the retries ran out. The reason is shown in the user's language, with the SMTP code | Anything about the recipient beyond that answer |

There is no tracking pixel and no IP address is stored.

## Limits

| Limit | Value |
|---|---|
| Sends per signed-in user | 30 a minute |
| Sends per firm | 500 a day |
| Test e-mails per user | 5 a minute |
| Link page and PDF per client address | 60 a minute |
| Recipients per send (To and Cc) | 15 |
| Other recipients typed in the dialog | 5 |
| Subject | 255 characters |
| Text | 10,000 characters |

## Backups

The PDFs as sent are kept in `<APP_SHARE_DIR>/<environment>/sent-documents` (`var/share/prod/sent-documents` by default), each under its organisation and with its SHA-256 on the send. Back this directory up together with the database: a send without its file cannot show its PDF, and a file whose hash no longer matches is refused.

## Personal data

*This section is subject to professional legal review.*

The send log holds the recipients' addresses, the subject, the text, the PDF as sent and the moment the link was first opened. This is personal data. It is kept with the invoice for the invoice's retention period, as described in [ADR 0011](adr/0011-audit-roots-and-retention.md), because it is the record of what the customer received. Foresi stores no IP address, uses no tracking pixel and builds no profile of a person. After the retention period the addresses and the text may be pseudonymised under the same ADR. During the period the log is not erased on request; the operator states this in their privacy notice, including that opening a link is recorded.

## Accepted differences from the plan

These were decided while sending was built and replace the plan where they differ:

- **Read** counts only an open or a download the user started (a click, or a request the browser marks as user-initiated). The PDF embedded in the page never counts. An honest read state was preferred to a higher count.
- The link page shows the payment details and the QR code of the document, and drops the repeated document number, since customers pay from it.
- Amounts in Czech crowns read `Kč` on a Czech document (PDF, link page, mail) and `CZK` on an English one. ISDOC keeps the ISO code.
- The send dialog's text is filled in before it is shown, so only the link is a placeholder there; braces in a customer's name or a description stay text.
- A refused send puts the keyboard focus on the first field to fix, as Foresi's other forms do; the error summary takes the focus only for a refusal that names no field.
- The form warns about a change after sending from the invoice's history (a newer version and a change from the second of the send on), so the delivery a send records does not count as a change.
- The reason of a failed send is stored as a code with the SMTP code and shown in the user's language. Sends from before keep their English text.
- iDoklad's own send dialog was not opened while this was built, to avoid mailing a real customer; the dialog follows iDoklad's help.
