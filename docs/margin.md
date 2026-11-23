# Margin

The Margin module (`src/Margin`, id `margin`, package `foresi/margin`, scopes `margin:read` and `margin:write`) says **who bears the cost** of a received document: the firm itself (`own`) or one of its clients, a contact. It keeps that mapping and turns it into **cost shares**: for every line of a document, how many Czech crowns each recipient bears, in the month of the document.

The module is optional. It requires Shared, Instance, Party, Identity, Audit and Ui. Cash flow, Invoicing and Purchasing are optional and list Margin as optional (ADR 0033). Margin owns its contracts (`Foresi\Margin\Contract`); Purchasing implements them in `Foresi\Purchasing\Integration\Margin` and shows the panel of Margin on a received document in `Foresi\Margin\Integration\Purchasing`. Without Purchasing there are no costs to map, and without Margin Purchasing works as before.

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

For each line of a recorded document, in this order:

1. the line's own allocation: source `line`;
2. the assignment of the document: source `document`;
3. nothing: an **unassigned** share, source `unassigned`, with no recipient.

No cost is silent: a line nobody claimed is shown as unassigned, never left out. The sources `planned_item` and `supplier` are reserved for costs that Cash flow plans and for default recipients per supplier; the module does not produce them yet.

## Shares

A share is counted in **CZK from the base of the line without VAT** (the base in CZK of the line, see Purchasing), in whole hellers, and is stored in the month of the document, a calendar month in the instance's time zone.

- A **percent** split rounds down per share and gives the remainder to the last share in the order typed: 30, 50 and 20 percent of 100.01 CZK give 30.00, 50.00 and 20.01.
- A **fixed** amount is capped at what the line has left; the `rest` share takes the remainder, which is zero when the fixed amounts use up the line. Fixed 800 on a line of 650 gives the client 650 and the rest 0.
- A **credit note** gives negative shares.
- A share of zero is not stored.

Shares are recalculated whenever the mapping changes and whenever the document is recorded, edited, trashed or restored. A trashed document has no shares; its mapping stays for a restore.

## Known gaps

A line without a base in CZK, because a foreign document has no exchange rate yet, gets **no share**, and the document is reported in `known_gaps` as `missing_exchange_rate`. The panel says so on the document and on the line. The shares appear when the rate is set.

## On the screen

A recorded received document shows a **Margin** panel with its lines, the shares of each and their sources. **Assign…** in the panel gives the whole document to a recipient or clears it. **Split…** on a line opens a dialog to choose the kind and the recipients, with a client picker that searches the contacts. A draft or trashed document has no panel to change.

## Operations

The same three operations are served by the REST API and by MCP, and refuse the same way:

| Operation | REST | MCP |
| --- | --- | --- |
| `margin.documents.get` | `GET /api/v1/margin/documents/{documentId}` | `foresi_get_document_allocation` |
| `margin.line_allocations.set` | `PUT /api/v1/margin/documents/{documentId}/lines/{lineKey}/allocation` | `foresi_set_line_allocation` |
| `margin.documents.assign` | `POST /api/v1/margin/documents/{documentId}/assign` | `foresi_assign_received_document` |

See [Margin in the REST API](api.md#margin) and [the margin tools in MCP](mcp.md). A change is recorded in the audit log with the recipients it gave the cost to.

## Refusals

A mapping that cannot be followed is refused with a reason: `whole_needs_one`, `percent_must_add_up`, `fixed_needs_a_rest`, `duplicate_recipient`, `unknown_contact` (a client that is no active contact), `unknown_document`, `unknown_line`, and `kind_invalid`, `share_invalid`, `recipient_invalid` or `amount_invalid` for a value that could not be read. A guest is refused as `forbidden`.
