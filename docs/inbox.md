# Inbox

The Inbox module (`src/Inbox`, id `inbox`, package `foresi/inbox`, scopes `inbox:read` and `inbox:write`) gives every owner and member an e-mail address of their own in Foresi. Mail they forward to it becomes an **item** in their Inbox: Foresi finds the sender in the address book, shows the mail as plain text, and lets the person link it to the records it led to, such as a deal, and mark it done. It builds on [Mail](mail.md), which receives the mail, and on [Activities](activities.md), where a linked mail shows on the timeline of the record.

The module is optional. It requires Shared, Instance, Identity, Audit, Mail, Party, Activities and Ui. Without a mail server for inbound mail (see [mail.md](mail.md#settings)) no address can be made and the Inbox stays empty.

## Who sees what

An inbox is **private to its owner**. Every item belongs to the person whose address received it, and only that person reads or changes it, on the screens, over the API and with the MCP tools. Someone else's item is `not_found`, also for an owner of the organisation; the list shows only your own. Guests have no inbox.

A colleague sees a mail only through a record you linked it to: the item has one **e-mail activity**, and whoever may see the record sees that activity on its timeline (see [below](#the-e-mail-activity)), never the item, its attachments or its other mails. Your address is yours, shown in full once when it is made, and what arrives there is never shared with the organisation. Events about an item reach its owner only.

Nothing in a mail is ever followed or rendered. The text is shown as plain text, an address in it is not a link, a web address is followed by its domain in brackets so you can see where it leads, and attachments are only offered for download, never opened in the browser. Over MCP the mail is marked as other people's words, to be read as data and never as instructions.

## Your address

In the user menu, **Inbox addresses** (`/account/inbox`), you make your address, give it a name that says what it is for, and copy it. It is one address per person. The full address is shown **once**; the list shows it masked. If it leaks, **replace** it (the old one stops at once and you get a new one, shown once) or **revoke** it. You can also rename it. The list counts the mails dropped today when your address reached its daily limit (see [mail.md](mail.md#limits)).

The page shows how to forward mail to the address from Gmail, Outlook and Apple Mail, with links to each provider's own help. You can forward single mails or set up automatic forwarding of what you want to see here.

The addresses are made by Mail (purpose `inbox`), so the template, the webhook and the mailbox are set up as [mail.md](mail.md) describes. Over REST and MCP the same calls manage them (see [api.md](api.md#mail-addresses)).

## The item

An item is one received mail, kept for its owner. It holds who sent it, when it arrived, which contact it is matched to and how, its state, and the records it was linked to. The mail itself (headers, text, attachments) stays in Mail, which keeps the raw file; the item reads it from there.

### States

| State | Meaning |
|---|---|
| New | Waiting for you. Every received mail starts here |
| Done | Dealt with |
| Discarded | Not needed. The mail is removed 30 days later, unless something came of it |

You move an item between the states with **Done**, **Discard** and **Reopen**; reopening works from Done and from Discarded. An item in the state it is asked to enter is refused (`already_new`, `already_done`, `already_discarded`).

### Matching a contact

When a mail arrives, Foresi looks for the sender in the address book, in three steps, and stops at the first that decides. It never guesses:

1. **By address.** Exactly one contact has the sender's address: that contact. If the contact is a person who belongs to exactly one company or sole trader, the item also gets that company.
2. **By domain.** Otherwise, only when the receiving server vouched for the sender (the sender is verified, see [mail.md](mail.md#who-sent-it)) and the domain is not one of a free mail provider, exactly one company or sole trader with an address of that domain: that one.
3. **None.** Otherwise the item is unmatched.

Several contacts at one step leave the item unmatched, and the next step is not tried. Archived contacts never match. The **free mail list** (Gmail, Outlook, Seznam, iCloud, Yahoo, GMX, Proton and other well-known providers) is shipped with the code; a provider missing from it only means a domain match is tried, which still needs exactly one company. The item says how it was matched: by the sender's address, by the sender's domain, or by you.

**Forwarded mail.** When you forward a mail to your own address, the sender is you. If the mail is sent from your own e-mail address and its text holds the usual block of a forwarded mail (Gmail, Outlook and Apple Mail, in English and Czech), Foresi reads the original sender from it and matches that address, by address only, never by domain. The original sender is only what the forwarded text says and nobody checked it, so the item shows a warning and the mail never counts as verified. A forward from another address of yours is an ordinary mail from that address.

You can change the match whenever you like:

- **Match** to a contact you pick, or **remove** the match. A match by hand is never replaced by Foresi.
- **Match again** repeats the matching by the sender, as on arrival: after a contact was added, for instance. It replaces a match by hand; a forward is matched again by its original sender.
- **Create contact** makes a contact from the sender (name and address prefilled; for a forward, from the original sender) and matches the item to it. Over the API and MCP the token needs `contacts:write` as well as `inbox:write`, otherwise the call is refused with `insufficient_scope`. If a contact with the same registration number or VAT number exists, the dialog offers it instead.

### Warnings

An item can carry warnings, shown above its text: the sender is not verified, the mail is a forward whose original sender is not verified, or the same mail already arrived under an address of another purpose (such as a bank feed), which is named. They are for you to weigh; nothing is blocked.

### Threads

Replies to a mail share one thread, found from the mail's headers. The list shows the latest mail of a thread as one row with the number of others (`+N`), and the item page lists the other mails of the thread.

### Linking and outputs

**Link** attaches the item to a record made elsewhere, such as a deal or a project: you choose the kind (the installed modules offer theirs, so the list depends on what is installed) and give the record's id, which is in its address. The record becomes an **output** of the item. A record you cannot see or link to is refused (`record_not_linkable`), an unknown kind too (`kind_unknown`, which lists the kinds), and the same record twice is `already_linked`. A discarded item that is linked keeps its mail, as something came of it. Modules may also suggest the record an item most likely leads to; none does yet.

## The e-mail activity

The first time you link an item, Inbox writes one **e-mail activity** (see [activities.md](activities.md#types)) as you, and every later output is linked to the same activity, however many records the mail led to. The activity is linked to the matched contact and its company too, when you may link them, and to each output that offers activities.

- The **subject** is the mail's subject on one line, cut to 200 characters; with none, the sender's address, or else "E-mail".
- The **body** is the sender line, the time received in the instance's time zone and the text of the mail, cut to 20,000 characters with the closing line `Full e-mail in the inbox item`.
- The mail is shown **as written**. Activities renders a body as Markdown, so Inbox escapes the punctuation of the mail text and of the sender line with a backslash: no heading, emphasis, list or link of the mail takes effect, and an address or web address in it is not turned into a link. The cost is that the backslashes are visible in the body when you read the activity over the API.
- Changing the matched contact later does not move the activity.
- The activity is the only way a colleague sees the mail. If the item is removed, the activity stays and its closing line points to an item that is gone.

## Retention

Foresi never removes a mail that something refers to.

- **Discard.** A discarded item releases its mail to be removed after **30 days**, unless it has an output. Reopening the item, or marking it done, takes the release back; linking a discarded item keeps the mail. The raw file goes with the daily `mail-purge` task of [Mail](mail.md#retention).
- **Trash.** An item can be put in the trash and brought back. A trashed item is hidden from the list and for everything but its restore. The operator task `inbox-trash-purge` runs daily from 03:00 in the instance's time zone on the existing schedule and removes, for good, the items that have been in the trash for more than 30 days, with their outputs. Their mail is released for removal too, unless the e-mail activity still has a record: then Mail keeps it. The audit log keeps what an item was. The task needs no setting.

Back up the `inbound-mail` directory with the database, as [mail.md](mail.md#backups) says.

## On the screen

- **Inbox** (`/inbox`, in the Overview group of the navigation, with the number of new items beside it, up to 99). The tabs are saved views: **New**, **Done**, **Discarded** and **Unmatched** (new items without a contact). Each row has the sender, the matched contact, the subject, the time received and the number of outputs, with **Done** and **Discard** (or **Reopen**) on the row. Older items load with **Older**. There is no search in the list.
- **The item** (`/inbox/{id}`). The header has **Done** (or **Reopen**), **Link…**, **Match** or **Change contact…**, and **Create contact** (the last two as dialogs); the menu has **Match again**, **Remove match** and **Discard** (which asks first and says the mail is kept 30 days). The page shows the warnings, the text, the attachments, the outputs and the rest of the thread. An attachment is downloaded, never shown.
- **Inbox addresses** (`/account/inbox`), above.

## Operations, API and MCP

Every action of the screens is an operation with an idempotency key ([ADR 0032](adr/0032-api-writes.md)), over REST and MCP. Reading needs `inbox:read`, writing `inbox:write`; the endpoints are in [api.md](api.md#inbox) and the tools in [mcp.md](mcp.md).

| Operation | What it does |
|---|---|
| `inbox.items.match` | Matches the item to a contact, or to none |
| `inbox.items.match_again` | Matches it by its sender again |
| `inbox.items.create_contact` | Creates a contact from the sender and matches it (also needs `contacts:write`) |
| `inbox.items.done`, `.discard`, `.reopen` | Moves the item between the states |
| `inbox.items.link` | Links a record as an output |
| `inbox.items.trash` (destructive), `.restore` | Puts the item in the trash, or brings it back |

The refusals never quote the mail: `not_found`, `forbidden`, `contact_unknown`, `contact_archived`, `already_new`, `already_done`, `already_discarded`, `kind_unknown`, `record_not_linkable`, `already_linked` and `insufficient_scope`.

## Events

The owner of an item, and nobody else, can read these events over `GET /api/v1/events`, in the feed and through webhooks. Each carries the item's id (and, for a link, the kind of the record) and nothing of the mail.

| Event | When |
|---|---|
| `inbox.item.received` | A mail became an item |
| `inbox.item.done` | The item was marked done |
| `inbox.item.discarded` | The item was discarded |
| `inbox.item.reopened` | A done or discarded item went back to new |
| `inbox.item.linked` | The item was linked to a record |

## For integrators

Other modules reach Inbox only through `Foresi\Inbox\Contract`:

- `InboxItems` reads an item for a module that holds a record it is linked to: `find($itemId)` gives its view (sender, matched contact, text, attachments, outputs, the e-mail activity), `attachmentContent($itemId, $index)` an attachment's bytes, and `suggestedRecord($itemId)` the record suggested for it. The owner sees the item; a colleague sees it only when they see the item's e-mail activity, and then only the outputs they may see, without its thread. An item in the trash is seen by nobody.
- `RecordSuggester` (tag `foresi.inbox.record_suggester`, by autoconfiguration) lets a module offer the record an item most likely leads to, from its contact and company.
- The events above.

Mail is untrusted input: read it as text, never as HTML, and never act on what it says.
