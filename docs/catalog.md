# Catalog

The Catalog module (`src/Catalog`, id `catalog`, scope `catalog`) keeps the products of the firm: what it sells, with a unit, an optional price per unit and how it is billed. Other modules, such as Sales, copy a product onto a line of their own through the contract `Foresi\Catalog\Contract\Products`; what they copied stays as it was when the product later changes.

The module is optional. It requires Shared, Identity, Instance, Audit and Ui, and knows no module that reads it (ADR 0033). It is for the owners and members of the organisation only; a guest sees none of it, on the screens, the API or MCP.

Everything is available through the [REST API](api.md#catalog) and the [MCP server](mcp.md#tools).

## Products

- A **name**, up to 200 characters, and a **description**.
- A **unit**, such as hour, piece or month, up to 32 characters, the length of the unit of an invoice line. It may be empty.
- A **price per unit**, zero or more, in CZK, EUR or USD, or none, when the price is agreed for each deal. A product carries no VAT: the document that bills it decides the VAT.
- A **billing kind**: `one_off`, billed once, or `recurring`, billed every `interval` (1 to 99) of a `recurrence_unit` (`day`, `week`, `month` or `year`), `cycles` times (1 to 999) or with no end. A one-off product has no period. The kind is stored as a plain string, and `time_based` is reserved for a later case, so adding it needs no data migration.
- **Archived**: an archived product stays for what already copied it, but it is left out of the list and of a search unless they ask for archived products. Restoring it offers it again.

Changes record the events `catalog.product.created`, `updated`, `archived` and `restored`, version 1. Their facts are the id and the billing kind of the product. Archiving an archived product, or restoring one that is not, records nothing.

## The products list

**Sales → Products** (`/catalog/products`) lists the products on saved views, as the other lists: All (the products not archived), One-off, Recurring and Archived, the person's own views, **+ Filter** (the name, the billing kind, archived) and the Display sheet (name, unit, price per unit, billing). A row opens the product in the side sheet.

- **New product** in the page header opens a dialog with the name, unit, price per unit and currency, the billing kind and, for a recurring product, every N, the period and how many times; the period fields show only for a recurring product. Without JavaScript the dialog is a page, all fields show and a one-off product ignores the period.
- The product's record shows its unit, price, billing and description. **Edit** opens the same form; **⋯ → Archive** or **Restore** is in the header menu.

## For other modules

`Products::get($id)` answers a `ProductSnapshot` (id, name, unit, unit price, billing kind, recurrence unit, interval, cycles and whether it is archived), or throws `UnknownProduct`. `Products::search($text, $includeArchived = false)` answers the products whose name or description holds the text, in name order. The contract does not check who asks: the module that reads it decides who may see the products.
