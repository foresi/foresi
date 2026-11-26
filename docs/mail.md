# Mail

The Mail module receives inbound mail for the other modules. It reads the operator mailbox over IMAP and accepts mail forwarded to a webhook, keeps the raw message on disk, finds the address it was sent to, says who the sender is (verified, failed or unknown) from what the receiving server wrote about it, and hands it to the module that owns the address. What a module does with the mail is that module's own business: Banking, for example, turns the notices of a bank account into payments, as [bank-pairing.md](bank-pairing.md) describes, and Inbox turns the mail a person forwards to their own address into items for them, as [inbox.md](inbox.md) describes. The decision behind the module is [ADR 0043](adr/0043-mail-module.md).

## What Mail owns

- **The two routes in**: the IMAP poll and the webhook, below.
- **Addresses.** An inbound address is a plus address of the operator mailbox with a secret token in it, such as `mail+<token>@example.com`. It belongs to a **purpose**, a module's own kind of mail, such as a bank feed. Some addresses belong to a person, who makes them for themselves ([below](#a-persons-own-addresses)).
- **The raw message**, stored on disk first, and a row per message with its headers, size, channel and verdict.
- **The sender verdict**, the same for every purpose.
- **The contracts** other modules use.

Mail has no screen and no scope of its own.

## How mail reaches Foresi

```
sender ──mail──▶ operator mailbox (mail+<token>@example.com) ──IMAP poll or webhook──▶ Foresi
```

The operator runs **one** mailbox for the whole installation. Each address a module asks for is a plus address of that mailbox with a token in it. A message is routed by the token in its recipient headers; a message for no known address is not stored.

### IMAP poll

The operator task `mail-inbox` runs every five minutes on the existing `foresi` schedule, under a lock, so two workers never poll at once. It takes the 50 oldest messages, reads them without marking them read, stores each one and then **moves** it to `Foresi/Done`, `Foresi/Unknown` or `Foresi/Rejected`. Messages are never deleted and the mailbox is never expunged. A message whose handling failed stays and is taken again; after five failures it is moved to `Foresi/Rejected`. The folders, the handling of servers without `MOVE` and the failure counts are described in [bank-pairing.md](bank-pairing.md#how-bank-mail-reaches-foresi). An empty `MAIL_INBOUND_HOST` turns polling off. The worker command is unchanged (see [sending.md](sending.md#the-running-worker)).

### The relay webhook

```
POST /inbound/mail
Authorization: Bearer <INBOUND_WEBHOOK_SECRET>
X-Foresi-Recipient: mail+<token>@example.com     (optional)

<the raw RFC 5322 message>
```

For a relay that receives the mail itself, such as an e-mail worker of a mail routing service. Empty `INBOUND_WEBHOOK_SECRET` turns it off (404). The answers, the rate limits and an example relay are in [bank-pairing.md](bank-pairing.md#the-webhook). The webhook answers 202 for a stored message, a duplicate, a message for an unknown or revoked address and a message dropped over the daily limit alike, so the answer says nothing about which addresses exist or how full they are. The body is at most 25 MiB (413 above that).

## Addresses and purposes

The address template is `MAIL_INBOUND_ADDRESS`, with `{token}` where the token goes:

```
MAIL_INBOUND_ADDRESS=mail+{token}@example.com
```

The mailbox must deliver every plus-addressed variant into the one inbox, or a catch-all must do the same for a template such as `{token}@mail.example.com`. Without a template no address can be issued.

- A token has 128 random bits, written as 26 characters of lowercase base32. The database keeps its SHA-256, for the lookup, and, only for a purpose that shows its address again, a copy sealed with `MAIL_SECRET_KEYS` (see [sending.md](sending.md#passwords-mail_secret_keys)). A copy of the database alone does not reveal an address.
- **Rotating** an address replaces its token on the same row; the old address stops at once. **Revoking** it stops it for good. Mail for either is treated as for an unknown address.
- Foresi finds the token in the first header, in this order, that holds an address made from the template: `X-Foresi-Recipient` (webhook only), `Delivered-To`, `X-Original-To`, `Envelope-To`, `X-Envelope-To`, `X-Forwarded-To`, `To`, `Cc`.
- A **purpose** is a module's registration for its addresses: a key, a label, whether an owner is required, whether the address can be shown again, and `accept()`, which the receiver calls inside the transaction that stores the message. A module registers one by implementing `Foresi\Mail\Contract\InboundPurpose`, which carries the tag `foresi.mail.inbound_purpose` by autoconfiguration. A message for an address whose purpose key has no registered consumer is dropped unread and nothing is stored. Banking registers `bank_feed`, Purchasing `received_documents`, and Inbox registers `inbox`, whose addresses belong to one person each and turn their mail into items for that person ([inbox.md](inbox.md)).
- `accept()` may refuse a message by throwing `Foresi\Mail\Contract\NotAccepted`: the receiver rolls the transaction back, removes the raw file and treats the message as for an unknown address (Banking does so for an archived bank account). Any other exception rolls back, removes the file and is thrown again, so the message is taken again.

### A person's own addresses

A purpose whose `ownerRequired()` is true has addresses that belong to one person, and each person manages their own over REST and MCP (scope `mail_addresses`, see [api.md](api.md#mail-addresses)): create, rename, revoke, replace and list. The rules:

- One address per person and purpose that is not revoked, enforced by a plain unique index (`uniq_mail_inbound_address_owner_purpose`) on the owner kept in `active_owner_user_id` while the address is live (ADR 0026), so two requests at once cannot make two. A second `create` is `address_exists`; a revoked address does not count.
- The full address is in the answer that creates or replaces it, once, with `Cache-Control: no-store`. A list shows `masked_address` only (the template with the token cut out), as the database holds only the hash of the token.
- **Replace** revokes the old address and issues a new one with the same purpose and label in one transaction, so the old one stays active when the new one cannot be made.
- A purpose that has no owners (`bank_feed`) is not managed here: its addresses are as unknown to these calls as another person's, and Banking manages them on the bank account. Guests have no addresses.

### Limits

- **Size.** A message is at most 25 MiB. A larger one is answered 413 on the webhook and moved to `Foresi/Rejected` over IMAP.
- **Count.** One address takes at most `MAIL_INBOUND_DAILY_LIMIT` mails (500 by default) per day of the instance, counted from midnight in the instance time zone. Only stored mail counts: a duplicate or a refused message takes no place. For the rest of the day the address drops mail unread. The webhook still answers 202, and over IMAP the message is moved to `Foresi/Unknown`, as for an address nobody knows, so a sender learns nothing from the answer.
- **What a person sees.** The address row counts the mails dropped that day: `dropped_today` in the list, with `limit_reached_today` true when it is above 0, over REST and MCP. The count starts again the next day.
- **What the operator sees.** The log gets one warning per address and day, at the first dropped mail. It names the address by its id and the limit, never the token or the address.

## The contracts

Other modules reach Mail only through `Foresi\Mail\Contract`:

| Contract | What it gives |
|---|---|
| `InboundPurpose` | A module's consumer of its addresses, described above |
| `AddressIssuer` | Issue, rotate, reveal (only for a purpose that shows its address again) and revoke an address; `unavailable()` says why no address can be issued |
| `Messages` | Read a stored message: the view (`findMany` for a list's messages in one query), the raw bytes, the plain text, the attachments (each says whether the sender embedded it inline, and its `Content-ID`), the parsed mail, the authentication results in header order and the sender verdict |
| `MailMessageView` | The id, address, purpose, channel, headers, sender, subject, dates, verdict, SHA-256 and size of one message |
| `InboundMail` | The parsed message that parsers read |
| `CrossPurposeDuplicates` | `of($messageId)` lists, oldest first, the other stored messages with the same `Message-ID` and the same bytes that arrived under an address of another purpose, each as message id and purpose key. They are not merged: a consumer may tell the person the mail came twice |
| `OwnAddresses` | The signed-in person's own address of a purpose, for a module that shows it on its own screen: `mine($purpose)` (the address that is not revoked, masked), `create`, `rename`, `replace`, `revoke` and `reveal`, the last for the person's own address only. The same rules and refusal reasons as the REST and MCP calls (`Foresi\Shared\Contract\Refused`) |
| `AddressOwners` | `ownerOf($addressId)`: the person an address belongs to, for `accept()`, which runs with nobody signed in; null for an unknown or revoked address and for one of nobody |
| `MessageRetention` | `release($messageId, $after)` schedules the removal of a discarded message's row and raw file; `keep($messageId)` takes the release back |
| `Event\MailMessageReceived` | The event `mail.message.received` |

### The event

When a purpose has accepted a mail, `mail.message.received` is recorded in the same transaction. It carries the message id and the key of the purpose, and nothing of the mail: no address, sender, subject or text. The consumer that owns the purpose reads the message through `Messages`. A mail that is refused, fails or is a duplicate records no event. Only the owners and members of the organisation may read the events of Mail, over `GET /api/v1/events`, in the feed and through webhooks. The arrival of a mail for a person's own address is seen by that person only, and one for an address nobody owns, such as the bank feed, by every such reader.

### Retention

Nothing removes a message until its consumer releases it, for example when the person discards the item made of it. The operator task `mail-purge` runs daily and removes, a batch at a time, the row and the raw file of each message whose release date has passed. The row goes first, then the file. A row another table still refers to is kept with its file, and the task logs a warning with its id and tries again the next day. `release()` with a later date replaces the earlier date, and `keep()` takes a release back, as when a discarded item is restored. Both ignore a message that is already gone.

### Adding a purpose

A module that wants mail of its own does four things:

1. Implement `Foresi\Mail\Contract\InboundPurpose`: a stable `key()` (at most 40 characters), a `label()`, `ownerRequired()`, `reshowable()` and `accept()`. Autoconfiguration tags the class. `ownerRequired()` decides who makes the addresses: true means each person creates their own over REST and MCP; false means the module issues them itself through `AddressIssuer`, as Banking does for a bank account.
2. Read the mail through `Messages`, never from the table or the files, and treat every field of it as untrusted text.
3. Do its work inside `accept()`, which runs in the transaction that stores the message, or listen to `mail.message.received`.
4. Call `MessageRetention::release()` when it discards a message, so the daily purge removes it, and `keep()` when it takes the discard back. A message nobody releases stays for good.

## Dedup, the raw store and the row

A message is stored once per address. Its dedup key is the SHA-256 of its `Message-ID`, or else of its bytes. A `Message-ID` is the sender's word, so it merges only copies with the same bytes: a copy of a stored `Message-ID` with other bytes is kept under the SHA-256 of its bytes. The same message sent to two addresses is a row for each.

The receiver works in this order: the size cap, the recipient headers and the token, the address (an unknown or revoked one stops here), the daily limit, the dedup key, the raw file, and then the row, the purpose's `accept()` and the event in one transaction. A crash between storing and moving a message in the mailbox costs nothing: the next poll finds it by its dedup key. A crash after the file is written and before the row is committed can leave an orphan file, which nothing refers to.

Raw messages are files in the share directory (`var/share/<env>/inbound-mail`, the parameter `app.inbound_mail_dir`), outside the web root and outside the database, each named after the message and kept readable only by the application user. They are the evidence of what was recorded from them: include the directory in backups, together with the database. The row, table `mail_message`, holds the headers that threading and matching need, the size, the SHA-256, the channel (`imap` or `webhook`), when it was received and the verdict. Mail text is untrusted: it is never rendered as HTML, and a verdict is the only thing Foresi believes about its sender.

## Who sent it

Foresi checks no signature itself. It reads **only the topmost `Authentication-Results`** header, which the operator's own receiving server adds above every header the message arrived with, and only when its authserv-id is trusted for the channel the message came by: `MAIL_INBOUND_TRUSTED_AUTHSERV` for the mailbox, `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` for the webhook, never one for the other. The verdict, stored on the message when it arrives, is one of:

- `verified`: that header holds exactly one DMARC result, a `dmarc=pass` whose `header.from` the sender's domain aligns with, and no lower header carries a trusted authserv-id; or the strict ARC rescue applies, with the sealer listed in `MAIL_INBOUND_TRUSTED_ARC`;
- `failed`: a claimed aligned `dmarc=fail` or `dkim=fail`, or a contradictory or planted header;
- `unknown`: nothing trusted vouches for the message.

Without trusted servers no message is verified. A purpose may judge the sender against domains of its own, as Banking does against the domains of the bank; with none, the sender's own `From` domain is used. The rule, how to find the authserv-id of your server and how to check the setup are described in [bank-pairing.md](bank-pairing.md#what-foresi-believes).

## Settings

All empty by default, so inbound mail is off until set. Set the password outside committed files, for example in `.env.local` or the service environment.

| Variable | Meaning |
|---|---|
| `MAIL_INBOUND_ADDRESS` | The address template, with `{token}`. Empty: no address can be issued |
| `MAIL_INBOUND_HOST` | The IMAP server. Empty turns polling off |
| `MAIL_INBOUND_PORT` | 993 for `ssl` (the default), usually 143 for `starttls` |
| `MAIL_INBOUND_ENCRYPTION` | `ssl` (the default) or `starttls`. There is no unencrypted mode, and the certificate is always checked |
| `MAIL_INBOUND_USER`, `MAIL_INBOUND_PASSWORD` | The login |
| `MAIL_INBOUND_FOLDER` | The folder read, `INBOX` by default |
| `MAIL_INBOUND_DAILY_LIMIT` | How many mails one address takes per day of the instance, 500 by default; the rest is dropped unread (webhook: still answered 202; mailbox: moved to `Foresi/Unknown`), see [Limits](#limits) |
| `MAIL_INBOUND_TRUSTED_AUTHSERV` | The authserv-ids of the mailbox's own receiving server, comma separated |
| `MAIL_INBOUND_TRUSTED_ARC` | The signing domains of trusted ARC sealers, comma separated |
| `INBOUND_WEBHOOK_SECRET` | The bearer secret of the webhook. Empty turns it off |
| `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` | The authserv-ids of the relay's own receiving server, comma separated |

Inbound addresses also need `MAIL_SECRET_KEYS`.

### The old names

Before the Mail module, the `MAIL_INBOUND_*` settings were called `BANK_INBOX_*`, one for one (`BANK_INBOX_ADDRESS`, `BANK_INBOX_HOST` and so on). For one more release, a `BANK_INBOX_*` setting is read when its `MAIL_INBOUND_*` name is empty or missing, and the log gets one deprecation warning per old name and process. The warning names the setting, never its value. When both are set, the new name wins. Rename the settings in `.env.local` or the service environment before the next release removes the fallback. `INBOUND_WEBHOOK_SECRET` and `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` keep their names.

## Upgrading

- Run `bin/console doctrine:migrations:migrate`. Mail adds the tables `mail_inbound_address` and `mail_message`; Banking's migrations then move the token of each bank account and the generic columns of each stored message into them, with the same ids, so every address, raw file, movement and link keeps working. The raw files stay where they are.
- Rename the settings, as above. The operator task `bank-inbox` is now `mail-inbox`; the worker command is unchanged.
- Messages stored before the move have no thread headers and a verdict of `unknown` in `mail_message`; Banking's own trust of those messages is unchanged.

## Backups

Back up the `inbound-mail` directory with the database: a message row without its file, or a file without its row, is lost evidence. See [operations.md](operations.md#backups).
