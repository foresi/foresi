# Margin

The Margin module (`src/Margin`, id `margin`, package `foresi/margin`, scopes `margin:read` and `margin:write`) says **who bears the cost** of a received document: the firm itself (`own`) or one of its clients, a contact. It keeps that mapping and turns it into **cost shares**: for every line of a document, how many Czech crowns each recipient bears, in the month of the document.

The module is optional. It requires Shared, Instance, Party, Identity, Audit and Ui. Cash flow, Invoicing and Purchasing are optional and list Margin as optional (ADR 0033). Margin owns its contracts (`Foresi\Margin\Contract`); Purchasing implements them in `Foresi\Purchasing\Integration\Margin` and shows the panel of Margin on a received document in `Foresi\Margin\Integration\Purchasing`. Without Purchasing there are no costs to map, and without Margin Purchasing works as before. The mappings of planned items need Cash flow; without it only lines, documents and supplier defaults map costs.

## Who works with it

The margin is the firm's, not a person's: the owners and the members read and change it, on the screens and with a token that holds `margin:read` or `margin:write`. A guest never sees it, and a guest's connection never lists or calls its tools.

## The mapping

A **recipient** is `own` or the id of an existing, active contact. A document is mapped in two layers:

- **The whole document** goes to one recipient (*Assign…* on the document). It covers every line that has no mapping of its own.
- **A line** has its own **allocation**, which wins over the assignment of the document. Its `kind` is:
  - `whole`: one recipient bears the whole line;
  - `percent`: two or more recipients, each with a percent such as `33.33`, adding up to exactly 100;
  - `fixed`: recipients with an amount in CZK, and exactly one recipient marked `rest`, who bears what is left.

A line is named by its **key**, the id of the stored line of the received document (see [Purchasing](purchasing.md#lines)). The key stays the same when the line is edited, moved or an earlier line is removed, so a mapping follows its line. When a line is removed from the document, its mapping is dropped. A document without lines has one implicit line per VAT rate of its recap, keyed `rate:<rate>` (for example `rate:21`); those keys exist only while the document has no lines.

## Precedence

For each line of a recorded document, the first that applies wins:

1. the line's own allocation: source `line`;
2. the assignment of the document: source `document`;
3. the mapping of the planned item the document is paired with, in force in the month of the occurrence: source `planned_item`;
4. the default recipient of the document's supplier, in force in the month of the cost: source `supplier`;
5. nothing: an **unassigned** share, source `unassigned`, with no recipient.

No cost is silent: a line nobody claimed is shown as unassigned, never left out. A share from a planned item carries `planned_item_id` and `occurrence_on` (the day of the occurrence the document is paired with), so a client can tell which item and which occurrence gave it.

## Planned-item mappings

A **recurring planned cost** in [Cash flow](cash-flow.md) can say who bears the documents paired with it, so a monthly hosting invoice needs no mapping of its own each time. The mapping has the same shape as a line's allocation (`whole`, `percent` or `fixed`). Setting one is refused unless the item is a cost (money going out), is recurring and has a **counterparty**, the supplier whose documents get paired with it (`not_an_expense`, `not_recurring`, `counterparty_required`). Ending a mapping needs none of that.

- **From a month on.** A mapping is versioned by a *from month*, a calendar month in the instance's time zone written `YYYY-MM`. The mapping for an occurrence is the version with the latest from month that is not after the month of the occurrence. A version with no allocation **ends** the mapping from its month, so later occurrences fall through to the next rule. Setting a month that already has a version replaces it. Shares of earlier months are never recalculated: a change applies from its month on.
- **Month of a cost.** For a document paired with an occurrence it is the month of the occurrence, which can be months after the document's own date (a quarterly or yearly item). Otherwise it is the month of the document's tax point, or its issue date where it has none, in the instance's time zone.
- **Fixed amounts are per occurrence.** A `fixed` mapping gives each listed recipient that amount for the occurrence, and the `rest` recipient what is left. When several documents are paired with one occurrence, they fill the fixed amounts in the order of their dates, then by id, and the rest goes to the `rest` recipient. So pairing or unpairing a document recalculates every document of its occurrence, and the one it left.
- **A credit note** under a fixed mapping is split on its own: its negative shares follow the same rule on its own amount, and it neither gives back to nor uses up the fixed amounts of the occurrence.
- **Recalculation.** Setting a mapping from month M recalculates, at once, the documents paired with occurrences in M or later. A pairing change in Cash flow (a document paired, unpaired or moved) recalculates asynchronously: Margin listens to the event `cash-flow.document_pairing.changed` in the events worker, so the shares of a document trail its pairing until the worker has delivered the event, and the worker must run. The calculation reads the stored pairings, so a late or repeated delivery leaves the right shares.

## Supplier defaults

A **default recipient** says who bears the costs of a supplier that nothing else claims: `own` or one client, never a split. It is versioned by a from month exactly as a planned-item mapping is, and a version with no recipient ends the default from its month. It applies to a line with no mapping of its own, no assignment of its document and no planned-item mapping, and takes the whole line. Setting a default from month M recalculates, at once, the supplier's documents whose cost month is M or later; earlier months keep their shares. The supplier must be an active contact.

## Shares

A share is counted in **CZK from the base of the line without VAT** (the base in CZK of the line, see Purchasing), never from the planned amount, in whole hellers, and is stored in the month of the cost (see [Planned-item mappings](#planned-item-mappings)), a calendar month in the instance's time zone.

- A **percent** split rounds down per share and gives the remainder to the last share in the order typed: 30, 50 and 20 percent of 100.01 CZK give 30.00, 50.00 and 20.01.
- A **fixed** amount is capped at what the line has left; the `rest` share takes the remainder, which is zero when the fixed amounts use up the line. Fixed 800 on a line of 650 gives the client 650 and the rest 0.
- A **credit note** gives negative shares.
- A share of zero is not stored.

Shares are recalculated whenever the mapping changes and whenever the document is recorded, edited, trashed or restored. A trashed document has no shares; its mapping stays for a restore.

## Documents recorded before Margin

Shares are counted when a document is recorded, edited, trashed or restored, so the documents that were recorded before Margin was installed have none yet. Run `bin/console margin:recalculate` once after installing Margin next to existing documents. It counts the shares of every recorded document of every cost source again, as if it had just been recorded, and prints the number of documents and shares. A mapping that was set is kept, and a second run changes nothing.

## Known gaps

A line without a base in CZK, because a foreign document has no exchange rate yet, gets **no share**, and the document is reported in `known_gaps` as `missing_exchange_rate`. The panel says so on the document and on the line. The shares appear when the rate is set.

## On the screen

A recorded received document shows a **Margin** panel with its lines, the shares of each and their sources. **Assign…** in the panel gives the whole document to a recipient or clears it. **Split…** on a line opens a dialog to choose the kind and the recipients, with a client picker that searches the contacts. A draft or trashed document has no panel to change. Over REST and MCP a trashed document still accepts a mapping, which waits for its restore.

## Operations

The same operations are served by the REST API and by MCP, and refuse the same way:

| Operation | REST | MCP |
| --- | --- | --- |
| `margin.documents.get` | `GET /api/v1/margin/documents/{documentId}` | `foresi_get_document_allocation` |
| `margin.line_allocations.set` | `PUT /api/v1/margin/documents/{documentId}/lines/{lineKey}/allocation` | `foresi_set_line_allocation` |
| `margin.documents.assign` | `POST /api/v1/margin/documents/{documentId}/assign` | `foresi_assign_received_document` |
| `margin.planned_allocations.set` | `PUT /api/v1/margin/planned-items/{plannedItemId}/allocation` | `foresi_set_planned_item_allocation` |
| `margin.planned_allocations.get` | `GET /api/v1/margin/planned-items/{plannedItemId}/allocation` | `foresi_get_planned_item_allocation` |
| `margin.supplier_defaults.set` | `PUT /api/v1/margin/suppliers/{contactId}/default-recipient` | `foresi_set_supplier_default_recipient` |
| `margin.supplier_defaults.get` | `GET /api/v1/margin/suppliers/{contactId}/default-recipient` | `foresi_get_supplier_default_recipient` |

See [Margin in the REST API](api.md#margin) and [the margin tools in MCP](mcp.md). A change is recorded in the audit log with the recipients it gave the cost to.

## Refusals

A mapping that cannot be followed is refused with a reason. For planned items and suppliers: `invalid_month` (a month not written `YYYY-MM`), `unknown_planned_item` (also where Cash flow is not installed), `not_an_expense`, `not_recurring`, `counterparty_required` and `unknown_supplier`. For every mapping: `whole_needs_one`, `percent_must_add_up`, `fixed_needs_a_rest`, `duplicate_recipient`, `unknown_contact` (a client that is no active contact), `unknown_document`, `unknown_line`, and `kind_invalid`, `share_invalid`, `recipient_invalid` or `amount_invalid` for a value that could not be read. A guest is refused as `forbidden`.
