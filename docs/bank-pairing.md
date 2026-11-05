# Payments and bank pairing

Foresi records payments against invoices in two ways: by hand, in the Pay dialog of an invoice, and from the payment notifications a bank mails. A notification goes to a secret address of the bank account; Foresi reads it, records the movement and pays the invoice it belongs to when the rules leave exactly one answer. A forged or unreadable message never pays anything and stays visible, and every pairing can be undone.

This page describes how to set the bank mail up on a server, what Foresi believes and why, and what the screens do. The banks supported in this release: Komerční banka. The decisions behind it are in [ADR 0019](adr/0019-payments-and-allocations.md) and [ADR 0020](adr/0020-bank-notification-mail.md).

## Paying an invoice by hand

"Pay" in the row menu of the invoice list opens a dialog with the date (today in the firm's country), the amount (what is left to pay), the method (bank transfer, cash or card) and a note. A part payment is allowed. An amount above what is left shows a warning line, "The invoice will be overpaid by …", and still saves. A date in the future is refused.

The invoice then shows one of the payment states, which are derived from its payments and never stored:

| State | When |
|---|---|
| Unpaid | Nothing is paid, or the invoice has nothing to pay |
| Partly paid | Something, but less than the total, is paid; the list shows what is left |
| Paid | Exactly the total is paid |
| Overpaid | More than the total is paid |

The status of an invoice is the first that applies of: in the trash, overpaid, paid, overdue, partly paid, delivered, issued. An invoice is overdue only while it is unpaid or partly paid. The list has the tabs All, Paid (paid and overpaid), Unpaid (unpaid and partly paid), Overdue and Trash.

The invoice form lists its payments, each with Remove. Removing a bank payment returns its movement to the unmatched queue. The history of the invoice shows "Paid" and "Payment removed", with who and when. An invoice with a payment cannot go to the trash: remove the payments first. It can still be edited, and its state follows: a raised total turns paid into partly paid, a lowered one into overpaid.

The public link page of a paid or overpaid invoice says "Paid" and shows no account, no QR code and no other way to pay it twice; see [sending.md](sending.md#the-public-link).

## How bank mail reaches Foresi

```
bank ──mail──▶ bank inbox (bank+<token>@example.com) ──IMAP poll or webhook──▶ Foresi
```

1. The operator runs **one** mailbox for the whole installation, the bank inbox.
2. Each bank account of a firm gets its own inbound address on that mailbox, with a secret token in it: `bank+<token>@example.com`. The owner enters it in the bank as the recipient of payment notifications.
3. Foresi takes the mail from the inbox, by polling it over IMAP every five minutes, or from a relay that posts it to a webhook, or both.
4. The token in the recipient routes the message to its organisation and account. A message for no known token is not stored.
5. The raw message is stored, then processed in the background: its sender is judged, the bank's parser reads it, and the movements are recorded and paired.

### The inbound address

The server's address template is `BANK_INBOX_ADDRESS`, with `{token}` where the token goes:

```
BANK_INBOX_ADDRESS=bank+{token}@example.com
```

The mailbox must deliver every plus-addressed variant, `bank+<anything>@example.com`, into the one inbox, or a catch-all must do the same for a template such as `{token}@bank.example.com`. Check this with your mail provider before handing out addresses: some providers drop or reject plus addressing unless it is turned on.

- The owner generates the address under **My firms › (firm) › Bank › Pairing**, per account.
- A token has 128 random bits, written as 26 characters of lowercase base32. The database keeps only its SHA-256 hash, for the lookup, and a copy sealed with `MAIL_SECRET_KEYS` (see [sending.md](sending.md#passwords-mail_secret_keys)), so the address can be shown again. Without a key no address can be generated. A copy of the database alone does not reveal any address.
- "Generate a new one" replaces the token. The old address stops working at once: mail sent to it is treated as for an unknown address. Enter the new address in the bank, or in the forwarding rule, straight away.
- The address is the only secret. Whoever knows it can send mail to it; what such mail can do is limited by the [trust rule](#what-foresi-believes) and the [account check](#the-account-check).
- Without `BANK_INBOX_ADDRESS` the Pairing tab says that bank mail is not set up on this server, and no address can be generated.

Foresi finds the token in the first header, in this order, that holds an address made from the template: `X-Foresi-Recipient` (webhook only), `Delivered-To`, `X-Original-To`, `Envelope-To`, `X-Envelope-To`, `X-Forwarded-To`, `To`, `Cc`. Where the mail was delivered comes first, as a forward keeps the original `To`.

### Polling the inbox over IMAP

```
BANK_INBOX_HOST=imap.example.com
BANK_INBOX_PORT=993
BANK_INBOX_ENCRYPTION=ssl
BANK_INBOX_USER=bank@example.com
BANK_INBOX_PASSWORD=
BANK_INBOX_FOLDER=INBOX
```

| Setting | Meaning |
|---|---|
| `BANK_INBOX_HOST` | The IMAP server. Empty turns polling off |
| `BANK_INBOX_PORT` | 993 for `ssl`, usually 143 for `starttls` |
| `BANK_INBOX_ENCRYPTION` | `ssl` or `starttls`. There is no unencrypted mode, and the certificate is always checked |
| `BANK_INBOX_USER`, `BANK_INBOX_PASSWORD` | The login. Set the password outside committed files, for example in `.env.local` or the service environment |
| `BANK_INBOX_FOLDER` | The folder read, `INBOX` by default |

The task `bank-inbox` runs every five minutes on the existing schedule, under a lock, so two workers never poll at once. Each run opens a new session, takes the 50 oldest messages that are not marked deleted, reads them without marking them read, and then **moves** each one:

| Folder | What lands there |
|---|---|
| `Foresi/Done` | Stored, or stored before (a duplicate) |
| `Foresi/Unknown` | For no known address, or an address that was replaced |
| `Foresi/Rejected` | Above 10 MiB, or not readable as mail, or its handling failed five times |

A message whose handling failed stays in the inbox and is taken again on the next run. After five failures it is moved to `Foresi/Rejected`, and the log says so. One the server will not even move there stays in the inbox for the operator and is left out of every later run, so bad messages at the head of the inbox never keep the good ones behind them from being read. The counts are kept in the application cache for a week; a lost count only starts over. The folders are created on the first run, under the server's personal namespace when it names one (below `INBOX.` on Courier and Cyrus, for example) and with the server's own hierarchy delimiter.

Messages are moved, never deleted, and the inbox is never expunged. Moving rather than marking read means an operator who opens the mailbox loses nothing, and every message stays where a person can find it again. On a server without the IMAP `MOVE` command, with or without `UIDPLUS`, Foresi copies the message and flags the original deleted; as Foresi never expunges, the original stays until the operator's own client or the server does.

Polling needs the worker, which consumes `async` and `scheduler_foresi` already; the worker command is unchanged (see [sending.md](sending.md#the-running-worker)).

### The webhook

For a relay that receives the mail itself, such as an e-mail worker of a mail routing service, Foresi has an optional webhook:

```
POST /inbound/mail
Authorization: Bearer <INBOUND_WEBHOOK_SECRET>
X-Foresi-Recipient: bank+<token>@example.com     (optional)

<the raw RFC 5322 message>
```

Generate the secret and set it outside committed files:

```bash
php -r 'echo bin2hex(random_bytes(32)), "\n";'
```

| Answer | When |
|---|---|
| 202 | Stored, a duplicate, or for no known address: all alike, so the answer says nothing about which addresses exist |
| 400 | The body is not readable as mail |
| 401 | The bearer token is missing or wrong (the scheme `Bearer` is matched without case, the secret exactly) |
| 404 | `INBOUND_WEBHOOK_SECRET` is empty: the webhook is off |
| 413 | The body is above 10 MiB, by its stated length or, for a chunked body, after reading one byte past the limit; the rest is never read |
| 429 | More than 120 requests a minute with a wrong or missing secret from one client IP address, or more than 600 a minute with the secret |

The secret is checked first, in constant time. Failed checks have a budget of their own per client IP, which keeps guessing slow; requests with the secret count against a separate, higher budget, so a flood of bad bearers from a shared address, such as other workers of the same provider, never locks the real relay out. A secret of 256 random bits cannot be guessed anyway. Both limits count the client IP that Symfony gives, so behind a reverse proxy set `trusted_proxies` as described in [sending.md](sending.md#the-public-link). The route keeps no session and needs no sign-in.

An example relay, a Cloudflare Email Worker that posts every mail it receives:

```js
export default {
  async email(message, env) {
    const response = await fetch('https://invoices.example.com/inbound/mail', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${env.FORESI_INBOUND_SECRET}`,
        'X-Foresi-Recipient': message.to,
        'Content-Type': 'message/rfc822',
      },
      body: await new Response(message.raw).arrayBuffer(),
    });
    if (response.status !== 202) {
      throw new Error(`Foresi answered ${response.status}`);
    }
  },
};
```

Store the secret as a secret of the worker (`FORESI_INBOUND_SECRET` here), never in its code. A failed post throws, so the relay's own handling of a failed delivery applies; check your service's documentation for what it does then.

**Trust for the webhook is set apart from the inbox.** Mail posted to the webhook is believed only on the word of the relay's own receiving server, listed in `INBOUND_WEBHOOK_TRUSTED_AUTHSERV`, never on the servers of `BANK_INBOX_TRUSTED_AUTHSERV`: the two are different paths, and a relay that trusted the inbox's id would believe any sender who wrote it. Empty, the default, verifies no webhook mail, so none pays by itself; it is still recorded, unverified, for a person to pair after checking it in the bank.

The relay must stamp its **own** `Authentication-Results` at the **top** of every message it posts, above every header the message arrived with, as the result of its own SPF, DKIM and DMARC checks. A relay that only passes on what the message carried proves nothing: those headers may be the sender's. For a relay whose receiving server writes the header itself, the raw message as the relay receives it starts like this:

```
Authentication-Results: relay.example.net;
    dkim=pass header.d=kb.cz header.s=selector1;
    dmarc=pass header.from=kb.cz policy.dmarc=quarantine;
    spf=pass smtp.mailfrom=kb.cz
From: Komercni banka <info@kb.cz>
...
```

Then trust exactly that id for the webhook:

```
INBOUND_WEBHOOK_TRUSTED_AUTHSERV=relay.example.net
```

The id above is an example, and providers differ in whether and how they write the header: look at one raw message as the relay receives it, and at what the owner's Pairing tab shows (see [below](#checking-the-setup)), before you trust an id. A relay that cannot stamp its own results leaves every webhook mail unverified.

IMAP and the webhook can run together. The same message arriving both ways with the same bytes, or again after a crash, is stored once: the key is the SHA-256 of its `Message-ID`, or of its bytes when it has none. Anyone can send mail with the `Message-ID` of a notice the bank is about to send, so a copy whose `Message-ID` is stored already but whose bytes differ is stored as well, under the SHA-256 of its bytes: junk that arrives first never keeps the real notice out. Should both copies carry the same movement, such as one notice forwarded through two paths that changed its headers, the second movement is a **possible duplicate** and never pays anything.

## What Foresi believes

Anyone can send a mail that looks like the bank's, and anyone can write an `Authentication-Results` header into it. Foresi checks no signature itself. It takes the word of **your own receiving server**, the one that delivers into the bank inbox, and only in a form a sender cannot fake.

### The rule

A message is **verified** only when:

- its `From` names exactly one address, in a domain of the bank (for Komerční banka `kb.cz` and `kbinfo.cz`, and their subdomains);
- the **topmost** `Authentication-Results` header carries an authserv-id trusted for the channel the message came by: listed in `BANK_INBOX_TRUSTED_AUTHSERV` for mail polled over IMAP, in `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` for mail posted to the webhook;
- that header holds exactly one DMARC result, and it is `dmarc=pass` for a `header.from` the sender's domain aligns with, which is itself a domain of the bank;
- no `Authentication-Results` header below the topmost one carries a trusted authserv-id.

Or, by the strict ARC rescue, when the topmost trusted header says `arc=pass`, the newest ARC set (the highest `i=`) is sealed by a domain listed in `BANK_INBOX_TRUSTED_ARC`, and that set's `ARC-Authentication-Results` shows an aligned `dkim=pass` for the bank. The rescue overrides a DKIM signature that a forwarder broke on the way; it never overrides a `dmarc=fail`.

A claim of failure counts **from any `Authentication-Results` header**, whether its authserv-id is trusted or not: claiming a fail can only make the verdict stricter, so nobody gains by forging one. A message is **failed** when:

- any header claims an aligned `dmarc=fail`, or an aligned `dkim=fail` (in the trusted topmost header the ARC rescue may override a broken DKIM signature; it never overrides a fail any other header claims);
- the trusted header holds a pass and a fail for one signing domain (`header.d`), or two DMARC results: no honest check writes either, so a sender smuggled one in;
- a header lower down claims the trusted authserv-id. A receiving server removes or renames headers that carry its own id (RFC 8601 §5), so a trusted id further down was planted by the sender or misplaced;
- the `From` domain is not the bank's or names more than one address.

It is **unverified** when there is no trusted header at the top, or when the header gives no verifying result (a `dkim=pass` alone never verifies).

| Verdict | What happens |
|---|---|
| Verified | The movements are recorded and paired by the [pairing rules](#what-pairs-by-itself) |
| Unverified | The movements are recorded and the fitting invoices are suggested; **nothing is paired by itself**. The pair dialog warns that Foresi did not verify the payment with the bank, ticks and fills in nothing, and pairs only once the person ticks "I checked the payment in the bank". The application refuses the pairing without that confirmation, whatever sends it |
| Failed | The message is rejected and no movement is recorded. Only the owner can "Record anyway", after a warning that the message failed the sender check and may be forged; the audit log keeps who did and when. The movements are then unverified, as above |

Why so strict: a receiving server copies text the sender chose, such as the HELO name or the envelope sender, into its header without always quoting it, so a sender can make one header seem to hold a result the server never reached. A second DMARC result, or a pass and a fail for one signer, is how that shows, and then the message is failed. An attacker who can send mail must never get an invoice marked paid, neither by itself nor through a click that looks routine: anything Foresi cannot verify goes to a person, who checks it in the bank.

### What the operator must do

1. **Your receiving server must add its own `Authentication-Results` at the top of every message** it delivers into the bank inbox, with SPF, DKIM and DMARC results. Most hosted mail services do; a self-run Postfix needs a milter such as OpenDKIM with OpenDMARC, or rspamd, configured to add the header. Without it no message is ever verified.
2. **List its authserv-id in `BANK_INBOX_TRUSTED_AUTHSERV`.** The authserv-id is the first word of the header, before the first `;`. Open the raw source of any message in the bank inbox and read the topmost header:

   ```
   Authentication-Results: mx.example.com;
       dkim=pass header.d=kb.cz;
       spf=pass smtp.mailfrom=kb.cz;
       dmarc=pass header.from=kb.cz
   ```

   Here it is `mx.example.com`:

   ```
   BANK_INBOX_TRUSTED_AUTHSERV=mx.example.com
   ```

   List only your own receiving server. A list of several is comma-separated, for a provider that writes different ids from different hosts. Empty verifies no bank message, so nothing pairs by itself.
3. **List ARC sealers in `BANK_INBOX_TRUSTED_ARC`**, by the signing domain (`d=`) of their `ARC-Seal`, only for forwarders you trust to have checked the bank's signature, such as the mailbox the owner forwards from:

   ```
   BANK_INBOX_TRUSTED_ARC=forwarder.example.com
   ```

4. **If your inbox provider seals with ARC on receipt, list it too.** The newest ARC set is then the provider's own, so without it in the list no ARC rescue ever applies.

### Checking the setup

The owner's **My firms › (firm) › Bank › Pairing** tab shows, under each account's address, a check of the last e-mail that account received: the channel it came by (IMAP or webhook), the authserv-id of its topmost `Authentication-Results`, whether that server is trusted for the channel, and the verdict, such as "Verified sender (dmarc)" or "Sender not verified (no-results)". Nothing else of the mail is shown there. An id that is yours but shown as not trusted belongs in `BANK_INBOX_TRUSTED_AUTHSERV` (IMAP) or `INBOUND_WEBHOOK_TRUSTED_AUTHSERV` (webhook); an id that is not your server's means your server added no header of its own, and nothing from that path will ever verify.

When notifications arrive but stay unverified, open one under **Bank › Messages › Show**: the verification line says why. Compare its raw headers with the rule above.

## Setting up Komerční banka

KB mails payment notifications, "Oznámení o platbách", once in Czech and once in English, from `kb.cz` or `kbinfo.cz`. KB publishes a DMARC policy for both domains, so an honest receiving server always gives its mail a DMARC result.

**MojeBanka Business** can send notifications to any address:

1. In the directory of notification recipients, add a recipient with the account's inbound address.
2. In Notifications, open the payment notifications and choose the account.
3. Choose incoming payments and the e-mail channel.
4. Choose the inbound address as the recipient and confirm the settings.

**KB+** sends notifications to one address only, so keep your own there and forward:

1. In Settings, Notifications, turn on "By e-mail" for incoming payments.
2. In your own mailbox, add a rule that forwards these mails to the account's inbound address.

The forward keeps the bank's DKIM signature when the forwarder does not change the message. If the forwarder breaks it, the message is verified only by the ARC rescue, when the forwarder seals with ARC and is listed in `BANK_INBOX_TRUSTED_ARC`.

Most mail services ask the forwarding address to confirm before they forward to it; Gmail, for example, mails a confirmation code. That mail reaches Foresi like any other: it shows under **Bank › Messages** as "Not bank mail". Open it with Show, take the code or the link, and then Dismiss it.

KB+ allows one notification address for all accounts, so a notice about another account of the same firm is accepted on any address of that firm. A notice about an account of no firm of the address is rejected (see [the account check](#the-account-check)).

### The layout of the notices

The parser reads the fields amount and currency, your account number, counter-account number and name, due date, status of the payment, variable, constant and specific symbol, and the message for the beneficiary, in Czech and in English. Every place the notice states a payment, both languages and both the HTML and the text body, must agree, or nothing is recorded. Only a payment with the status booked (Zaúčtováno) is recorded; a rejected one is ignored.

The layout of incoming payment notices is **assumed** to mirror the outgoing ones, under the heading "Příchozí platba / Incoming payment". If KB's real wording differs, the parser fails visibly: the message shows under **Bank › Messages** as "The bank changed the layout of its e-mail" (unknown layout), keeps its raw form, and pairs nothing. A wrong amount is never recorded. Once a later version of the parser reads that wording, the hourly job `bank-reparse` reads those messages again by itself, and so does "Try again".

## The account check

Every movement in a message must be booked on an active account of the firm whose address it came to. Another account of the same firm is fine. Any other account rejects the whole message (`account_mismatch`) with no movement: it is either someone else's money or the wrong address. Such a message cannot be recorded anyway, only dismissed.

Money from another account of the same firm is an **own transfer** and never pays an invoice. A transfer between two firms of one organisation pays normally. Outgoing payments are **recorded** for the record and never pair by themselves; a person can pair one with a refund (below).

## What pairs by itself

Pairing works on incoming movements only, by the variable symbol and the amount:

- **M0 Gate.** A movement pairs by itself only when its message is verified, it is in the currency of its account, the firm's automatic pairing is on, and nobody unpaired it before. Otherwise it stays unmatched with the reason, and the fitting invoices are kept as suggestions.
- **M1 Own transfer.** A counter-account that is an account of the same firm makes an own transfer.
- **M2 Candidates.** The firm's invoices and advance invoices, never its tax documents on payment, that are not in the trash, are in the movement's currency, have something left to pay, and whose variable symbol equals the movement's, leading zeros ignored (`0002026001` matches `2026001`). No symbol: unmatched, "No variable symbol". No such invoice: unmatched, "No invoice has its symbol", or "The invoice is in another currency" when the only ones are.
- **M3 One candidate.** It takes the whole amount and becomes paid, partly paid or overpaid.
- **M4 Several candidates.** The one whose remaining amount equals the movement takes it. Failing that, a movement equal to what they all have left pays each its remaining amount. Failing that: unmatched, "Several invoices fit", with the oldest due first.
- **M5 Locking.** The movement and then its invoices, in a fixed order, are locked, and the decision is made again under the locks. Of two notices for one invoice processed at the same moment, the second sees what the first paid; of a person and the matcher on one movement, only the first pairs it.
- **M6 Outgoing.** Recorded, never matched by itself.

What never pairs by itself:

- an unverified or failed message, and a movement someone recorded anyway;
- a movement in another currency than its account: it stays unmatched with "The movement is in another currency than its account", which usually means the account's currency is set wrong;
- **an invoice issued after the money arrived**: it is only suggested, because money that came first was for something else, or for an invoice not yet written, and a person knows which;
- the same movement from another message: a **possible duplicate**, never paired;
- an own transfer and an outgoing payment;
- a movement someone unpaired.

A movement pays through at most one live payment: the database refuses a second one.

A payment of an advance invoice, paired by itself or by a person, gets its tax document in the same step, as one recorded by hand does; see [invoicing.md](invoicing.md#advances). A tax document on payment is paid by that payment and is never a candidate. A final invoice that deducts advances asks only for its own total, which the deductions already lowered, so the advance's money is never counted twice. Unpairing a payment of an advance trashes its tax document while the customer has not got it, and corrects a delivered one.

### Outgoing refunds

A refund is an outgoing payment: its allocation on the invoice is below zero, so what was paid is what came in less what went back. An invoice has something to refund when its payments exceed what is now due, after its credit notes or after a final invoice's deducted advances. "Pair" on an outgoing movement offers those invoices, first by the invoice's own variable symbol or that of its credit note, then by the amount, and records a refund of at most what is to refund. Nothing pairs by itself. Unpairing cancels the refund, and the movement goes back to recorded. A refund recorded by hand from the invoice's payment panel works the same way, without a movement. A refund of an advance corrects its tax document on payment; see [invoicing.md](invoicing.md#corrections).

### Pairing settings

Per firm, under **My firms › (firm) › Bank › Pairing**:

| Switch | Default | What it does |
|---|---|---|
| Automatic payment pairing | On | A verified payment pays its matching invoice by itself. Off: every movement waits for a person |
| Small-difference settlement | Off | Settles a small difference onto the invoice total |

The small-difference settlement applies only when the switch is on, the movement pays a single invoice, and the firm's country module gives a limit for the currency. The Czech module allows **up to 0,99 Kč in CZK** and nothing in any other currency. The payment then records the amount that came, and the difference is written off on the invoice so that it closes **exactly**: 999,01 Kč for an invoice of 1 000,00 Kč makes it paid, with 0,99 Kč settled; 998,99 Kč makes it partly paid. The difference may go either way. The settled amount counts toward the invoice only, never toward the payment.

## The bank screen

**Bank** in the navigation has four tabs:

| Tab | What it lists |
|---|---|
| Incoming | All incoming movements: date, account, amount, VS, partner, message, and the invoices paired with, or the state with its reason |
| Outgoing | Outgoing movements, for the record or paired by a person with a refund |
| Unmatched | Incoming movements waiting for a person; the tab shows their count |
| Messages | The bank mail: received, from, subject, status and the reason in words |

The list filters by firm, account and a search over partner, VS or amount. The shell also shows a notice while failed or rejected messages, or unmatched movements, wait.

- **Pair** opens a dialog with the suggested invoices first (same variable symbol, then same amount, then same customer) and a search over the firm's other unpaid invoices in the movement's currency. Tick one or more; the amounts fill in oldest due first, and the parts must add up to the movement. When the firm allows it, the difference on one invoice can be written off, up to the limit. Pairing needs no confirm: the flash "Paired with …" offers **Undo**, for ten minutes. Only the invoices the dialog offered can be paid, and an invoice paid meanwhile is refused under its lock.
- For a movement Foresi did not verify (listed as "Not verified, check it in the bank first"), the dialog warns, nothing is ticked or filled in, and the box "I checked the payment in the bank" must be ticked. Find the payment in your internet banking first.
- **Unpair** on a paired row asks first: the invoices become unpaid again, the payment is cancelled, and the movement returns to unmatched and is **never paired automatically again**. Undo from the flash does the same without asking.
- **Show** opens the message: its text as text, never as HTML, the sender, the account, the status and the verification. Only the owner sees an inbound address, so the token of any address the mail quotes, such as a forwarding confirmation does, is shown as `bank+…@example.com`, also when a link URL-encodes it or a long line wraps it.
- **Try again** processes a failed, waiting or not-bank message now. Pressing it twice records nothing twice. An unexpected error leaves the message as it was, says so in a flash and is logged.
- **Record anyway** exists only for a message that failed the sender check, and only for the owner. It warns that the message did not pass the bank-sender check and may be forged, then records the movements unverified and unpaired; they are never paired by themselves, and pairing them needs the bank check above. The audit log keeps who recorded it and when.
- **Dismiss** sets aside a message nobody will record: one about another firm's account, a failure no parser will read, a mail that is no bank's, or a stuck one. It is never processed again; the raw message stays stored, and the audit log says who dismissed it.

A message still waiting to be processed **after 60 minutes** is shown as **stuck**: the queue gave up on it, or no worker runs. It is listed among the messages that need someone, with Try again and Dismiss.

### Message states and failures

| Status | Meaning |
|---|---|
| Waiting | Stored, waiting for the worker |
| Processing | A worker has it now |
| Processed | Its movements are recorded |
| Ignored | A bank message that moves no money, such as a payment not booked yet |
| Not bank mail | No parser knows it, such as a forwarding confirmation |
| Failed | A parser knows it but could not read it, with one of the typed failures below; or its stored file changed or cannot be read |
| Rejected | Failed the sender check or the account check |
| Dismissed | Set aside by a person |

| Failure | Meaning |
|---|---|
| Unknown layout | The notice is the bank's, but its layout is not one the parser reads |
| Missing field | A field the payment needs is missing |
| Invalid value | A value cannot be read, such as an amount or a currency |
| Inconsistent | The parts of the notice disagree, such as the Czech and the English amount |
| Ambiguous | More than one bank's parser fits the mail |

The hourly job `bank-reparse` reads again, by itself, the failed messages whose parser has a newer version since, and the not-bank messages when the set of parsers changed. A rejected message, and one whose stored file changed, is not tried again: no parser changes that. An unexpected error during processing gives the message back to the queue, which tries it again.

## The security model

| Layer | What it stops |
|---|---|
| The secret token in the address | Mail for no known token is not stored. 128 bits cannot be guessed, the database holds only a hash and a sealed copy, and a new token kills the old address at once |
| The sender allowlist | A `From` outside the bank's domains, or with two addresses, is rejected |
| Trusted authentication results | Only your own receiving server's topmost header counts, only a single aligned `dmarc=pass` or the strict ARC rescue verifies, and anything unclear verifies nothing |
| The account check | A notice about an account that is not the firm's is rejected and cannot be recorded |
| The gate | An unverified message is recorded and suggested, never paid by itself; a person pairs it only after confirming it in the bank, and only the owner records a failed one anyway |
| The webhook secret and limit | Bearer token compared in constant time first, 120 failed attempts a minute per IP, a separate 600 a minute for the relay, 10 MiB at most, and the same answer whether an address exists |
| Undo | Every pairing, automatic or by hand, can be undone, and the movement then waits for a person |

Every write goes through one application path and is staff only, except `POST /inbound/mail`. The raw file is checked against its SHA-256 before it is read. Logs name the message, never the address.

## Backups

Raw messages are kept in `<APP_SHARE_DIR>/<environment>/inbound-mail` (`var/share/prod/inbound-mail` by default), one directory per organisation, readable by the server's user only, each file with its SHA-256 on its row. Back this directory up together with the database: a message without its file cannot be shown, retried or reparsed, and a file whose hash no longer matches is refused.

## Personal data

*This section is subject to professional legal review.*

Raw bank messages hold the names and account numbers of payers, the amounts, and the messages for the beneficiary. This is personal data. Foresi keeps each message as the evidence of the payment it recorded, for the accounting retention period, together with the movement and the payment. It sits in `inbound-mail` beside the database, and in its backups. A dismissed message stays stored as well. During the period the messages are not erased on request; the operator states this in their privacy notice. The bank inbox itself is the operator's mailbox: messages moved to the `Foresi/` folders stay there until the operator removes them, so set its retention to match.

## Upgrading

- Run `bin/console doctrine:migrations:migrate`. The new tables hold payments and their allocations, the firms' bank accounts, inbound addresses, pairing settings, inbound messages and bank movements.
- **Firm bank accounts became records.** The migration copies each firm's list in order and makes the first account the primary one. The list held no currency, so the migration guesses it from the firm's country: CZK in Czechia, EUR in a euro-area country, USD in the United States, and CZK elsewhere. Check each account under **My firms › (firm) › Bank › Accounts** and correct the currency of an account kept in another one. The currency locks once the account has a movement.
- **Country modules:** the interface `InvoicingCountry` has a new method, `settlementLimit(Currency $currency): ?int`, the most minor units a firm of the country may write off on an invoice in the currency, or null for none. A third-party country module must implement it; return null to keep small differences unsettled.
- **New environment variables**, all empty by default, so bank mail stays off until set: `BANK_INBOX_ADDRESS`, `BANK_INBOX_TRUSTED_AUTHSERV`, `BANK_INBOX_TRUSTED_ARC`, `BANK_INBOX_HOST`, `BANK_INBOX_PORT` (993), `BANK_INBOX_ENCRYPTION` (`ssl`), `BANK_INBOX_USER`, `BANK_INBOX_PASSWORD`, `BANK_INBOX_FOLDER` (`INBOX`), `INBOUND_WEBHOOK_SECRET` and `INBOUND_WEBHOOK_TRUSTED_AUTHSERV`. Inbound addresses also need `MAIL_SECRET_KEYS`.
- Include `inbound-mail` in backups.
- The worker command is unchanged.
