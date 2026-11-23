# Sales

The Sales module (`src/Sales`, id `sales`, scope `sales`) keeps the deals of the firm: what is being sold to a client, how far along it is, what it is worth, and whether it was won or lost. A deal has stages, lines copied from the catalog, links to the deals it follows from, and, when those modules are installed, its documents and activities.

The module is optional. It requires Shared, Party, Catalog, Identity, Instance, Audit and Ui; Documents and Activities are optional, and Sales never names a module that uses it (ADR 0033). Those modules plug into a deal through the slots in `Foresi\Sales\Contract\SalesSlots`.

## Staff only

Deals are for the owners and members of the organisation. A guest never sees the screens, the API or the MCP tools, whatever scopes their connection asks for ([ADR 0041](adr/0041-guests-over-mcp.md)).

Everything is available through the [REST API](api.md#sales) and the [MCP server](mcp.md#tools).

## Deals

- A **title**, up to 200 characters, such as "Roof audit for the warehouse", and a **description** in Markdown, up to 10,000 characters.
- A **client**: a contact of the address book. An archived client takes no new deal, nor a deal moved to it; its old deals stay and still read. The client can change: the deal then takes the contact persons given with the change, checked against the new client, or none. A deal linked to other deals keeps its client until the links are removed (`client_has_links`), as a link joins deals of one client.
- **Contact persons**: some of the client's contact persons, in order, whom the deal is talked through with. Only persons linked to the client are accepted.
- An **owner**: an owner or member of the organisation; the person who creates the deal when left out.
- A **stage** and a **status**.
- **Lines** and the **links** to other deals, below.

A deal goes to the trash and comes back as it was, lines and links included. In the trash it leaves the lists and a change is refused, except restoring it; trashing or restoring it again changes nothing.

## Stages and status

A **stage** is a step a deal moves through. The migration seeds Inquiry, Meeting, Proposal and Negotiation (keys `inquiry`, `meeting`, `proposal`, `negotiation`). A seeded stage has no name of its own: it shows under its key's name in the person's language until someone renames it, and giving it `null` brings the key's name back. A stage's key never changes, so events and integrations can name it. A new deal starts at the first stage unless another is named.

The **status** is `open`, `won` or `lost`:

- Only an **open** deal changes stage (`deal_not_open` otherwise). A won or lost deal keeps its stage.
- **Win** and **Lose** apply only to an open deal. Losing takes a **lost reason** and a note of up to 1,000 characters, both optional.
- **Reopen** applies only to a won or lost deal. It opens the deal again at the stage it was at and forgets when and why it was won or lost.

**Lost reasons** are kept as the stages are. The migration seeds Price, Timing, Chose another supplier, No response and Other (keys `price`, `timing`, `other_supplier`, `no_response`, `other`).

Stages and lost reasons are kept under **Settings → Sales**, and archived, never deleted: an archived one is no longer offered, and the deals that have it keep it. A stage that still holds open deals cannot be archived (`stage_has_open_deals`): move or close them first. Reordering takes every stage that is not archived once, and the order stays without gaps.

## Lines and value

A **line** is what a deal sells. It is copied from a product of the [catalog](catalog.md) with a quantity, or typed in with its own name, unit, price and billing, by the same rules as a product. A line has a name (up to 200 characters), a unit (up to 32), a quantity above zero with up to three decimals, an optional price per unit without VAT in CZK, EUR or USD, and a billing kind: `one_off`, or `recurring` every `interval` (1 to 99) of a `recurrence_unit` (`day`, `week`, `month` or `year`), `cycles` times (1 to 999) or with no end.

A line copied from a product stays as it was when the product later changes, as the Catalog describes. The lines can be put in any order.

The **value** of a deal is counted from its priced lines, per currency, and never converted between currencies:

- **One-off** is the sum of the one-off lines, quantity times price.
- **Monthly** is the sum of the recurring lines put on a month's terms: a monthly line counts its price divided by its interval, a yearly one a twelfth of it (divided by the interval), a weekly one 52/12 of it and a daily one 365/12. Each line is rounded once, half up, to the minor unit of its currency.
- A weekly or daily line makes the value **approximate**, shown with an **≈**, because a month has no whole number of weeks or days. Monthly and yearly lines are exact.
- A line without a price is left out. A deal without a priced line has no value.

## Follow-up deals

A deal can **follow from** another deal of the same client, such as a monthly service after a one-off audit. **New follow-up deal** on a deal opens a new deal for the same client and contact persons, lets you pick the lines to copy (as they are on the source deal), and links the two. A link can also be added to or removed from existing deals. A deal is never linked to itself (`link_self`), to a deal of another client (`link_other_client`), or twice to the same deal (`link_duplicate`). Both deals stay when a link is removed.

## The deals list

**Sales → Deals** (`/sales/deals`) lists the deals on saved views, as the other lists: All, Open, Won, Lost and Trash, the person's own views, **+ Filter** (status, owner, client, the trash) and the Display sheet (title, client, owner, value, status and more). A row opens the deal.

- **New deal** in the page header opens a dialog with the title, the client, the owner, the stage and the contact persons. Without JavaScript the dialog is a page.
- **Edit** on a deal opens the same dialog with its client in the same search field; a linked deal shows its client read-only, with a note that it changes once the links are removed.
- Once the client is known, the dialog lists its contact persons to tick (or says it has none yet), and they reload for another client chosen, with the persons ticked before dropped. **Add a person** under them adds a person to the client while the deal is saved, the same two ways as the contact's own dialog: a person of the address book, or a new one with a name, e-mail and position. The person is linked to the client, as its primary contact person when it has none, and becomes the last of the deal's contact persons. A refused person is shown at its field and nothing is saved. A person as the client has no contact persons, so neither shows for one.
- The deal's page shows its stage, status, client and contact persons, its lines and value, and the deals it follows from or is followed by. **Edit**, **Won**, **Lost**, **Reopen**, **New follow-up deal** and **⋯ → Delete** or **Restore** are in its header. Changing the stage and adding or editing lines open dialogs.

## On a contact

When Sales is installed, the page of a contact has a **Deals** tab: the deals of that client, each with its stage, status and value, and "Follows from ..." under a deal that follows another.

## Documents and Activities

These appear on a deal only when the modules are installed; without them the deal shows none of this and works the same (`Foresi\Sales\Integration\Documents` and `Integration\Activities` are then not registered). A deal in the trash shows neither.

- **Documents:** the deal's header has **New document**, which opens the dialog of Documents with the deal and its client linked, and the deal's page has a **Documents** section listing the documents linked to the deal. A document can link to a deal (record kind `deal`); a deal in the trash shows as unavailable on the document until it is restored. Templates read the deal under `{{ deal.* }}`:

  | Variable | Value |
  | --- | --- |
  | `deal.title`, `deal.owner`, `deal.stage`, `deal.status` | The deal's title, the name of its owner, its stage and its status (open, won, lost), in the language of the person who generates the document. |
  | `deal.lines` | The lines in their order, as rows with `name`, `unit`, `quantity`, `unit_price` (such as "12 000,00 Kč", empty for a line without a price) and `billing_period` (such as "One-off" or "Every month, no end"). |
  | `deal.one_off_totals`, `deal.monthly_totals` | The value once and every month, one row per currency with `currency` and `amount`, never converted. A monthly amount starts with "≈ " when a weekly or daily line had to be put on a month's terms. |

  The deal variables need a link to a deal that is not in the trash (the first `deal` link): without one a template that uses them is refused when it is generated. Like every variable, they are frozen with the document when it is generated.
- **Activities:** the deal's header has **Log activity** and **Plan activity**, and the deal's page has its timeline. The activity is linked to the deal, to its client and to the deal's contact persons, so it also shows on the timeline of each of them, and the dialog comes back to the deal. Owners and members see the activities of a deal; who sees an activity follows its records, as [activities.md](activities.md#who-sees-an-activity) says: a trashed deal no longer makes its activities visible.

The new-document and new-activity screens take several links as `links[]=kind:id`, repeated, besides the single `link_kind` and `link_id`, which still work.

## Events

`sales.deal.created`, `.updated`, `.stage_changed`, `.won`, `.lost`, `.reopened`, `.trashed` and `.restored`, version 1, about a subject of type `deal`. The payload holds the `dealId` and the `status` after the change; `stage_changed` adds the `fromStageId` and `toStageId`, and `lost` the `lostReasonId`. Changing the client, the lines, the links or the contact persons records `.updated`. The title, notes, prices and the lost note never travel in an event ([ADR 0030](adr/0030-domain-events.md)).

## API and MCP

The REST endpoints are in [api.md](api.md#sales) and the tools in [mcp.md](mcp.md#tools). Every write is an operation with an idempotency key ([ADR 0032](adr/0032-api-writes.md)).
