# REST API

Foresi has a REST API. Scripts and other systems use it to read the data of an organisation and to change its contacts. The decisions behind it are in [ADR 0027](adr/0027-api-and-mcp.md) and [ADR 0032](adr/0032-api-writes.md). Assistants use the [MCP server](mcp.md), which is built on the same code.

The API lists and shows invoices, contacts and payments, and follows the feed of domain events. It keeps the [cash flow](#cash-flow) of the firm and answers its forecast. It writes [contacts](#contact-writes) and [webhook endpoints](#webhooks): addresses of your own that receive the events as signed requests.

## Personal API tokens

Every request is signed in with a personal API token. A token belongs to one person in one organisation. It acts as that person: it sees what the person sees and nothing more. Guests cannot have tokens, and the REST API is not for guests: a guest connects their own agent over [MCP](mcp.md#guests) with OAuth sign-in ([ADR 0041](adr/0041-guests-over-mcp.md)).

### Issue a token

1. Sign in and open **Settings > API and MCP**.
2. Click **New token**.
3. Enter a name (where you will use it, such as `Reporting`) and choose how long it lasts: 30, 90 or 365 days.
4. Under **Access**, choose what the token may do: read everything, read and change everything, or read or change one kind of record, such as contacts.
5. Click **Create token** and copy the token.

The token is shown **once**. Foresi keeps only a hash, so it cannot show the token again. If you lose it, revoke it and issue a new one.

Rules:

- Only a person who has set up two-factor sign-in can issue a token. Without it the page shows a notice with a link to set it up.
- Give a token only the [scopes](#scopes) its program needs. An outreach agent that keeps contacts needs `contacts:write`, not `write`.
- A token always expires. There is no token without an end date.
- You see your own tokens. An owner also sees the tokens of the other people in the organisation and can revoke them.
- Issuing and revoking a token are recorded in the audit log. What a token does is recorded under the token, next to the person.

### Revoke a token

Open **Settings > API and MCP**, open the row menu of the token and choose **Revoke**. The token stops working at once. An expired or revoked token stays in the list, marked as such.

### Console commands for operators

The operator can manage tokens from the console. No two-factor sign-in is asked for here: whoever runs the console already holds the installation.

```bash
# Issue a token for a person.
bin/console app:api-token:issue anna@example.com --name="Reporting" --days=90 --scope=read
bin/console app:api-token:issue anna@example.com --name="Outreach" --scope=contacts:write

# List every token. Only the last four characters of a token are known.
bin/console app:api-token:list

# Revoke a token by its id.
bin/console app:api-token:revoke <token-id>
```

`issue` takes `--days` of 30, 90 or 365 (90 by default) and one or more `--scope`, which it requires: `read`, `write` or a resource scope such as `contacts:write` (see [Scopes](#scopes)). A scope no installed module declares is refused. The person must be the owner or a team member. The command prints the token once.

### Scopes

A scope says what a token may reach. A token has one or more:

| Scope | Grants |
| --- | --- |
| `read` | Every `:read` scope. |
| `write` | Everything, reading included. |
| `<resource>:read` | Reading one kind of record. |
| `<resource>:write` | Changing one kind of record, and reading it. |

The resources are `calendar`, `cashflow`, `mail_addresses`, `catalog`, `contacts`, `documents`, `invoices`, `payments`, `received_documents`, `sales`, `events`, `projects`, `tasks` (comments and labels included), `taxes`, `time_entries`, `vat`, `views` and `webhooks`; each module declares its own. Every endpoint needs one resource scope, named in the tables below. `GET /api/v1/me` needs a valid token and no scope. A request without the scope it needs is answered `403 insufficient_scope`, and `WWW-Authenticate` names the scope. Tokens issued before scopes per resource keep what they had: `read` reads everything, and `write` now also reads.

## Using the API

The base URL is `/api/v1` on the address of your installation. Send the token as a bearer token:

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" "https://app.example.com/api/v1/invoices?state=unpaid"
```

- The API is stateless. It never uses cookies or CSRF tokens.
- Answers are JSON (`application/json`), errors `application/problem+json`.
- Put the token in an environment variable or a secret store, never in a URL.
- The organisation comes from the token. An instance holds one firm ([ADR 0039](adr/0039-one-firm-per-instance.md)): read it with `GET /api/v1/firm`. It is still part of the data (`firm` on an invoice or payment), but no read or write takes a firm. A request that sends `firm_id` is refused with `400 invalid_parameter` and `parameter` `firm_id`, not ignored, so a client written for several firms does not read more than it asked for without noticing.

To check a set-up, call `GET /api/v1/me`. It returns the person and the token (its name, scopes and expiry). It never returns the secret.

### Money, dates and ids

- Money is an object with the amount in minor units: `{"amount_minor": 12100, "currency": "CZK"}` is 121.00 CZK.
- Dates are `YYYY-MM-DD`. Timestamps are ISO 8601 with an offset, such as `2026-11-10T14:30:00+01:00`.
- Quantities, percentages and discounts are strings with fixed decimals, such as `"1.0000"` and `"21.00"`.
- Ids are UUIDs.
- A field with no value is `null`, not left out.

### Lists and paging

A list answers with an envelope:

```json
{
  "data": [ { "id": "...", "number": "20260042" } ],
  "next_cursor": "MDE5MmYw..."
}
```

Lists are sorted newest first. `limit` is the size of a slice, 25 by default and at most 100. When `next_cursor` is not `null`, send it back as `cursor` to get the next slice, with the same filters. When it is `null`, you have everything (except in the events feed, see below). The cursor is opaque: do not build or change it. A cursor this API did not give is refused with `invalid_parameter`.

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50"

curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/invoices?state=unpaid&limit=50&cursor=MDE5MmYw..."
```

A detail answers `{"data": {...}}`.

## Endpoints

The endpoints below are `GET`. The writes of invoices are under [Invoice writes](#invoice-writes), recurring invoices under [Recurring invoices](#recurring-invoices), those of contacts under [Contact writes](#contact-writes), those of the firm's VAT statuses under [Firm VAT statuses](#firm-vat-statuses), those of saved views under [Saved views](#saved-views), those of the cash flow under [Cash flow](#cash-flow), those of documents under [Documents](#documents), those of received documents under [Received documents](#received-documents), those of projects, tasks and time under [Work writes](#work-writes), and those of webhooks under [Webhooks](#webhooks).

| Endpoint | Scope | What it returns |
| --- | --- | --- |
| `/api/v1/me` | none | The person and the token. |
| `/api/v1/invoices` | `invoices:read` | Sales documents, newest first. |
| `/api/v1/invoices/{invoiceId}` | `invoices:read` | One document with its lines, payments, corrections and balances. Each line has its `id` (what the [margin rebill pairing](#margin) takes as `invoice_line_id`) and `source_line_id`, the id of the template line it was issued from, or `null`. |
| `/api/v1/contacts` | `contacts:read` | Contacts, newest first. |
| `/api/v1/contacts/{contactId}` | `contacts:read` | One contact with its addresses, e-mails, bank accounts and contact persons. |
| `/api/v1/firm` | `contacts:read` | The firm of the instance: id, name, registration number, VAT ID, the VAT status of today (`vat_status`), its VAT statuses over time (`vat_statuses`, see [Firm VAT statuses](#firm-vat-statuses)), register entry and address. `404` before the first-run setup. |
| `/api/v1/payments` | `payments:read` | Payments, newest first. |
| `/api/v1/payments/{id}` | `payments:read` | One payment with the invoices it pays. |
| `/api/v1/received-documents` | `received_documents:read` | Received documents, newest first, with what is paid and what remains. |
| `/api/v1/received-documents/{documentId}` | `received_documents:read` | One received document with its VAT recap, lines and payments. |
| `/api/v1/received-documents/{documentId}/payments` | `received_documents:read` | The payments of one received document, by the day paid. |
| `/api/v1/events` | `events:read` | Domain events of the last 30 days, oldest first. |
| `/api/v1/projects` | `projects:read` | Projects the token's person sees, newest first. |
| `/api/v1/projects/{projectId}` | `projects:read` | One project with its statuses and the sections the person sees. |
| `/api/v1/projects/{projectId}/people` | `tasks:read` | Who may be responsible for a task of the project; `task_id` narrows it to who sees that task. |
| `/api/v1/tasks` | `tasks:read` | Tasks the token's person sees, subtasks included, newest first. |
| `/api/v1/tasks/{taskId}` | `tasks:read` | One task with its description, watchers and subtasks. |
| `/api/v1/tasks/{taskId}/comments` | `tasks:read` | The comments of a task, oldest first, without paging. |
| `/api/v1/labels` | `tasks:read` | The labels of the organisation, by name. |
| `/api/v1/time-entries` | `time_entries:read` | Time the person sees, newest first, a running timer included. |
| `/api/v1/cash-flow/forecast` | `cashflow:read` | The cash-flow forecast of the firm; see [Cash flow](#cash-flow). |
| `/api/v1/cash-flow/planned-items` | `cashflow:read` | The planned items of the firm, oldest first, without paging. |
| `/api/v1/cash-flow/planned-items/{plannedItemId}` | `cashflow:read` | One planned item. |
| `/api/v1/cash-flow/pairings` | `cashflow:read` | The pairings of received documents with planned occurrences, oldest first, filtered by `state` (`auto`, `confirmed`, `proposed`, `declined`) and `planned_item_id` (paired with the item, or listing it among the candidates). |
| `/api/v1/calendar/feeds` | `calendar:read` | Your calendar feeds, oldest first, without their addresses; see [Calendar](#calendar). |
| `/api/v1/mail/addresses` | `mail_addresses:read` | Your inbound mail addresses, oldest first, revoked ones too, without their tokens; `purpose` filters them. See [Mail addresses](#mail-addresses). |
| `/api/v1/catalog/products` | `catalog:read` | The products in name order, without paging; `q` finds a text in the name or description, `archived=true` includes the archived ones. See [Catalog](#catalog). |
| `/api/v1/catalog/products/{productId}` | `catalog:read` | One product. |
| `/api/v1/sales/deals` | `sales:read` | The deals not in the trash, newest first; see [Sales](#sales). |
| `/api/v1/sales/deals/{dealId}` | `sales:read` | One deal with its lines, value, links and contact persons. |
| `/api/v1/sales/stages` | `sales:read` | The stages of a deal, in order. |
| `/api/v1/sales/lost-reasons` | `sales:read` | The reasons a deal is lost for, in order. |
| `/api/v1/taxes-and-contributions/profile` | `taxes:read` | The owner profile, for the owner only; see [Taxes and contributions](#taxes-and-contributions). |
| `/api/v1/taxes-and-contributions/amounts` | `taxes:read` | The actual income and costs to date, for the owner only; see [Taxes and contributions](#taxes-and-contributions). |
| `/api/v1/vat/periods` | `vat:read` | The VAT periods of the firm with their due dates and amounts, an estimate; see [VAT](#vat). |
| `/api/v1/vat/periods/{key}` | `vat:read` | One VAT period with the documents, the recurring invoices and the plans behind its amounts; see [VAT](#vat). |
| `/api/v1/vat/settings` | `vat:read` | Where the firm pays its VAT; see [VAT](#vat). |
| `/api/v1/documents` | `documents:read` | Documents, newest first; see [Documents](#documents). |
| `/api/v1/documents/{documentId}` | `documents:read` | One document with its links, signers, files and frozen values. |
| `/api/v1/documents/{documentId}/files/{file}` | `documents:read` | The bytes of a stored file of a document: `pdf`, `signed` or `certificate`. |
| `/api/v1/document-types` | `documents:read` | The document types, by name. |
| `/api/v1/document-templates` | `documents:read` | The document templates, by name, without their bodies. |
| `/api/v1/document-templates/{templateId}` | `documents:read` | One template with its latest version, its versions and the variables a body can use. |
| `/api/v1/lists` | `views:read` | The lists saved views filter, with their fields, operators, values and columns; see [Saved views](#saved-views). |
| `/api/v1/views?list=` | `views:read` | The saved views of a list the token's person sees. |
| `/api/v1/views/{viewId}` | `views:read` | One saved view, by its id or the system key of one a module ships. |
| `/api/v1/views/{viewId}/count` | `views:read` and the list's scope | How many records the view holds now. |

### Invoices

Query parameters of `GET /api/v1/invoices`. All of them narrow the list.

| Parameter | Value |
| --- | --- |
| `kind` | `invoice`, `advance`, `payment_tax_document` or `correction_document`. |
| `customer_id` | The id of a contact. |
| `state` | `unpaid`, `overdue` or `paid`. |
| `trashed` | `true` lists documents in the trash. Default `false`. |
| `issued_from`, `issued_to` | Issue date range, `YYYY-MM-DD`, both ends included. |
| `q` | Text to search for. |
| `view` | The id of a saved view of a list of documents, or the system key of one a module ships, such as `invoicing.invoices.overdue`: only the documents it holds. Needs `views:read` too. Without `kind`, the kind of the view's list. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), such as `{"conditions": [{"field": "due_date", "op": "before", "value": {"relative": "today"}}]}`. Checked against the list of `kind`, or of the invoices without one. |
| `limit`, `cursor` | Paging, as above. |

All parameters are joined by AND, `view` and `filter` included. The answer has `data`, `next_cursor` and `applied_view`, the saved view `view` named, or `null`. A filter that cannot be used is answered `400 invalid_parameter`, with `parameter` naming its path, such as `filter.conditions[0].op`, and `reason` `filter_invalid`; a view of another list than `kind` is refused the same way with `parameter` `view`. A view the person does not see is `404 not_found`, and one whose conditions its list no longer has is `409 view_outdated`.

A list item has `id`, `number`, `kind`, `firm`, `customer`, `issue_date`, `due_date`, `tax_point_date`, `total`, `paid`, `remaining`, `payment_state` (`unpaid`, `partly_paid`, `paid`, `overpaid` or `to_refund`), `overdue`, `sent`, `sent_at`, `delivered`, `trashed` and `created_at`. `sent` says whether it was sent to the customer, by e-mail or marked by hand; `sent_at` is when it was first sent, or `null`; `delivered` stays as a deprecated alias with the same value as `sent`. The detail adds the customer's identification and address, the currency, the variable symbol, the lines with their VAT, the payments and corrections, and the balances.

### Invoice writes

Issuing and changing sales invoices and advance invoices needs `invoices:write`. Foresi has no drafts: a created invoice is issued at once, with the next number of its series, as saving the invoice form does. The writes run the same rules as the form, and answer `{"data": {...}}` with the document as `GET /api/v1/invoices/{invoiceId}` shows it.

| Method and path | What it does |
| --- | --- |
| `POST /api/v1/invoices` | Issues an invoice. `201` with `Location`. |
| `PATCH /api/v1/invoices/{invoiceId}` | Changes the fields given; a field left out keeps its value. |
| `POST /api/v1/invoices/{invoiceId}/trash` | Moves it to the trash with a `reason`. |
| `POST /api/v1/invoices/{invoiceId}/restore` | Brings it back from the trash. |
| `POST /api/v1/invoices/{invoiceId}/mark-sent` | Records that it went to the customer outside Foresi. It cannot be undone: it no longer goes to the trash and its number is fixed. One sent already is answered as it is; one in the trash is refused with 409 trashed. |

The body of `POST /api/v1/invoices`:

| Field | Value |
| --- | --- |
| `customer_id` | Required. The id of a contact with a billing address. |
| `lines` | Required. Objects of `text`, `unit_price` (a decimal string such as `"1500.00"`), and optional `quantity` (default `"1"`), `unit`, `price_type` (`without_vat` by default, `with_vat` or `base_only`), `vat` (`base`, `reduced`, `second_reduced`, `reverse_charge`, `exempt` or `none`) and `discount` (a percentage, such as `"10"`). Without `vat` a line gets the base rate, reverse charge for a customer taxed instead, or `none` for a firm that is no VAT payer. |
| `kind` | `invoice` (default) or `advance`. |
| `series_id` | A series of the firm and kind; the default series when left out. |
| `number` | A number typed by hand, such as one continuing the numbering of another system; it must fit the series and be free. Without it the series' counter gives the next number. |
| `issue_date`, `tax_point_date`, `due_date` | `YYYY-MM-DD`. Today, the issue date, and the issue date plus the customer's due days when left out. |
| `currency` | ISO 4217; the currency of the firm when left out. |
| `payment_method` | `bank_transfer`, `cash` or `card`; the customer's when left out. |
| `bank_account` | The IBAN of an account of the firm; its primary account when left out. |
| `variable_symbol` | Up to 10 digits; it follows the number when left out. |
| `constant_symbol`, `text_before`, `text_after` | The firm's defaults when left out. |
| `order_number`, `description` | The customer's order number, and what the invoice is for (the first line's text when left out). |
| `language` | `cs` or `en`; the customer's when left out. |

`PATCH` takes the same fields except `kind` and `currency`, which never change. `lines` replaces every line; left out, the lines stay as they are. `series_id` or `number` renumber the document, which is refused once it was sent, marked by hand (`number_locked`), sent by e-mail (`number_locked_sent`) or queued to be sent (`number_locked_sending`); a variable symbol that followed the number follows the new one. A document in the trash (`trashed`) and a tax document on payment (`tax_document_fixed`) do not change.

What the rules refuse is answered with every problem at once: the first one gives the status, the `reason` and, for `400`, the `parameter`, and `problems` lists them all as `"parameter: reason"`, such as `["variable_symbol: variable_symbol_invalid", "customer_id: customer_unknown"]`. A line is named by its position, such as `lines[0].unit_price`. A state of the data that does not allow the change, such as `number_taken`, `number_locked` or `trashed`, is `409`. The trash refuses a sent document and one already in the trash with `409 not_trashable`, and one with payments with `409 has_payments`; correct a sent document with a correction document in Foresi.

### Recurring invoices

A recurring invoice is a template Foresi issues a sales invoice from on every date of its schedule, as the recurring invoices screen keeps them. Reading needs `invoices:read`, the writes `invoices:write`; they run the rules of the recurring form and answer `{"data": {...}}` with the template as `GET /api/v1/recurring-invoices/{recurringInvoiceId}` shows it: its `version`, schedule, `delivery`, lines in the shape the writes take them, and `next_issues`.

| Method and path | What it does |
| --- | --- |
| `GET /api/v1/recurring-invoices` | The templates out of the trash, the soonest next issue first, 25 a page; `state` (`active`, `paused`, `ended`) and `page`. |
| `GET /api/v1/recurring-invoices/{recurringInvoiceId}` | One template with its lines and next issues. Each stored line has its `id`, which stays when the template is revised and is what the [margin rebill link](#margin) takes as `recurringLineId`; a preview has no ids. |
| `POST /api/v1/recurring-invoices/preview` | The fields of a create, and `count`: the template it would save and its next issues, without saving; `saves` is false with the `problems` the rules would refuse. Needs `invoices:read` only. |
| `POST /api/v1/recurring-invoices` | Creates one. `201` with `Location`. |
| `PATCH /api/v1/recurring-invoices/{recurringInvoiceId}` | Changes the fields given with the `version` it was read in; a save someone made since is refused with 409 `changed_meanwhile`. |
| `POST /api/v1/recurring-invoices/{recurringInvoiceId}/pause` | Pauses it; the dates that pass meanwhile are skipped. |
| `POST /api/v1/recurring-invoices/{recurringInvoiceId}/resume` | Resumes it from the next date on or after today. |
| `POST /api/v1/recurring-invoices/{recurringInvoiceId}/end` | Ends it for good. |

The body of a create: `delivery` is required, `send` (each invoice is e-mailed to the customer) or `review` (it is only issued and waits for a person to send it). `from_invoice_id` starts from an issued invoice as "Make recurring" does, taking its customer, lines, texts, payment, order number and due days; without it `customer_id` and `lines` (as for an invoice) are required. The schedule is `first_issue_on` (today or later), `repeat` (`day`, `week`, `month` by default, `year`), `every`, `last_day_of_month`, and `end`: `never`, `on_date` with `ends_on`, or `after_count` with `ends_after`. `tax_point` is `issue_date` or `last_day_of_month`, `due_days` 0 to 365. The rest are the fields of an invoice without its number and dates, and `name`, `copy_to_me` and `copy_to_others`.

### Contacts

Query parameters of `GET /api/v1/contacts`:

| Parameter | Value |
| --- | --- |
| `q` | Text to search for. |
| `role` | `customer`, `supplier`, `partner` or `authority`. |
| `archived` | `true` lists archived contacts. Default `false`. |
| `registration_number` | Company registration number. |
| `vat_id` | VAT id. |
| `view` | The id of a saved view of the list `party.contacts`, or the system key of one it ships, such as `party.contacts.suppliers`: only the contacts it holds. Needs `views:read` too. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), such as `{"conditions": [{"field": "role", "op": "is", "value": "supplier"}]}`. A condition on `archived` decides it instead of the `archived` parameter. |
| `limit`, `cursor` | Paging, as above. |

The answer has `data`, `next_cursor` and `applied_view`, and refuses a view or filter as the invoices do; a view of another list is refused with `parameter` `view`.

### Payments

Query parameters of `GET /api/v1/payments`:

| Parameter | Value |
| --- | --- |
| `invoice_id` | The id of an invoice the payment pays. |
| `direction` | `incoming` or `refund`. |
| `received_from`, `received_to` | Date received range, `YYYY-MM-DD`, both ends included. |
| `include_cancelled` | `true` also lists cancelled payments. Default `false`. |
| `limit`, `cursor` | Paging, as above. |

### Events

`GET /api/v1/events` is the feed of what happened in the organisation: an invoice was issued, sent (event type `invoicing.invoice.delivered`), paid, trashed or restored, a payment was recorded, a contact was created, updated, archived or restored, a VAT status of the firm was added or removed (`party.firm.vat_status_changed`), a VAT period's return amount, settled mark or payment changed (`vat.period.changed`), a received document was recorded, changed, trashed, restored or paid in full, a task was created, updated, completed or trashed, a task was commented on. An event of a task is shown only to who sees the task, as its lists show it: a private task or a project not shared with a team member stays out of their feed and their webhooks. Query parameters:

| Parameter | Value |
| --- | --- |
| `types` | Event names or `module.*`, separated by commas, such as `invoicing.invoice.paid,invoicing.payment.recorded`. Without it, every event the token's person may read. An unknown type is `400 invalid_parameter`; a type of a module the person may not read is `403 forbidden`. |
| `limit`, `cursor` | Paging, but the cursor is a position in the feed, and `next_cursor` is not `null` once you have seen an event; see below. |

An item has `id`, `type`, `version`, `position`, `occurred_at`, `subject` (`type`, `id` and the API path `url` to read it), `data`, `correlation_id` and `actor` (`kind`). The `data` holds ids, codes and amounts as text, and no personal data. The feed is in ascending order of `position`, and the events of the last 30 days are kept. To follow it, store the `next_cursor` of the last answer and call again with it as `cursor`: it continues after the last event you got. A page with fewer items than `limit` means you are caught up; call again later with the same `cursor`, and `next_cursor` stays where it was until something new happens. `next_cursor` is `null` only while you have seen no event at all. Delivery to you is at least once, so handle an `id` you have seen before. A type only gains facts within a `version`.

A value an endpoint cannot use, such as a date that is not a date or a `limit` above 100, is answered `400 invalid_parameter`, with the name of the parameter.

### Projects and tasks

Projects, tasks, comments and time are read as the token's person sees them on the screens (see [projects](projects.md)): the owners see everything, a team member the projects open to the organisation and what is shared with them. A project or task they may not see answers `404 not_found`, exactly as one that does not exist, and a private task is not found unless it is shared with them. A person is written as `{"id", "name"}`, the id being their membership in the organisation; that id is the `responsible_id` of a task.

Query parameters of `GET /api/v1/tasks`:

| Parameter | Value |
| --- | --- |
| `q` | Text to search for in the title. |
| `project_id` | Only the tasks of one project. Without it, the tasks of archived projects are left out. |
| `assignee` | `me` for the tasks the token's person is responsible for, or the id of a person. |
| `status_type` | `open`, `active`, `done` or `closed`. |
| `finished` | `true` for tasks in a status of type `done` or `closed`, `false` for the others. |
| `due` | `overdue` (before today and not finished), `today`, `next_7_days` (today and the seven days after) or `none`. |
| `view` | A saved view of the list `work.my_tasks`, such as `work.my_tasks.assigned` or `work.my_tasks.watching`. Needs `views:read` too. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), checked against `work.my_tasks`. |
| `limit`, `cursor` | Paging, as above. |

"My tasks" in an instance is `GET /api/v1/tasks?assignee=me&finished=false`. The order is by id, newest first, so a client that wants the tasks by due date sorts them by `due_on` itself.

Query parameters of `GET /api/v1/projects`: `q` (the name), `archived` (`true` lists the archived projects), `client_id`, `view` (a saved view of the list `work.projects`), `filter`, `limit` and `cursor`. `GET /api/v1/time-entries` takes `project_id`, `task_id`, `person` (`me` or the id of a person), `from` and `until` (`YYYY-MM-DD`, both included), `limit` and `cursor`; a running timer has `minutes` `null` and `running` `true`. Hourly rates and sharing stay on the screens.

A guest's agent reads the same answers over MCP, as the guest's screens show them: `estimate_minutes` of a task and `billable_by_default` of a project are `null`, and the people of a project are the organisation's own, not other guests.

## Saved views

A saved view is a named filter of a list, with its sort and columns, such as "Due this week" on the invoices. It is private to its owner or shared with every owner and member; the views a module ships, such as the tabs of the invoice lists, are shared and have a `system_key`, such as `invoicing.invoices.overdue`, which names them wherever an id does. Guests never see views. The reads need `views:read`, the writes `views:write`, and reading the records of a view also needs the scope of its list, such as `invoices:read`; the bank movements (`banking.movements`) are read with `payments:read`, by staff only.

`GET /api/v1/lists` describes every list views filter, so a client builds a filter without guessing. Each list has its `key`, its `fields` (`key`, `type`, `label`, the `operators` it takes and, for an enum, its `options`), its `columns` (`key`, `label`, `sortable`, `default`, `locked`) and its `default_views`. `date_values` lists the date values every date field takes.

A filter is `{"match": "all", "conditions": [{"field", "op", "value"}]}`, every condition met, at most 20; `match` may be left out. The operators by type:

| Type | Operators | Value |
| --- | --- | --- |
| `enum` | `is`, `is_not`, `is_any_of`, `is_none_of` | One of `options`, or a list of them. |
| `multi_enum` | `has_any_of`, `has_all_of`, `has_none_of`, `is_empty`, `is_not_empty` | A list of `options`. A record without any value has none of them. |
| `reference` | `is`, `is_not`, `is_any_of`, `is_empty`, `is_not_empty` | An id, or a list of ids. |
| `text` | `contains`, `does_not_contain`, `is`, `is_not`, `is_empty`, `is_not_empty` | A text; `contains` ignores case. |
| `number` | `eq`, `ne`, `lt`, `le`, `gt`, `ge`, `between`, `is_empty`, `is_not_empty` | A number; an amount in whole units. |
| `date` | `is`, `before`, `after`, `on_or_before`, `on_or_after`, `between`, `is_empty`, `is_not_empty` | A day `YYYY-MM-DD`, a month `{"month": "YYYY-MM"}` or a relative date. |
| `boolean` | `is` | `true` or `false`. |
| `relation` | `is_not_empty`, `is_empty` | None: the record has related records, such as a contact's `invoices`, or none. |

`between` takes `{"from": …, "to": …}`, both ends included; `is_empty` and `is_not_empty` take no value. A field of a related record, such as a contact's e-mail, matches when one of them matches, and its negation (`does_not_contain`, `is_not`, `is_empty`) when none does. A field of a related record's attribute is keyed after its relation, such as `customer.billing_city` on the invoices, the live contact rather than the invoice's own copy (`customer_city`); a relation to many, such as `projects` on the contacts, may have a `<relation>.name` text field that matches when one related record is so named and its negation when none is. A field may still answer to an older key, such as `role` for the contacts' `roles`; a condition on `role` with `is` reads as `has_any_of`. A relative date is resolved when the query runs, in the organisation's time zone: `{"relative": "today"}`, likewise `yesterday`, `tomorrow`, `this_week`, `last_week`, `this_month`, `last_month`, `next_month`, `this_quarter`, `last_quarter`, `this_year` and `last_year`, or a count of days from 1 to 365: `{"relative": "next_n_days", "n": 7}`, `last_n_days`, `n_days_ago` and `n_days_from_now`. A period with `is` covers all of it; `before` is before it starts and `after` after it ends.

A view has `id`, `list`, `name`, `system_key`, `visibility` (`private` or `shared`), `owner_id`, `filters`, `sort`, `display`, `show_count`, `placement` (`primary` or `overflow`), `position`, `hidden`, `may_edit` (whether the token's person may change it), `created_at` and `updated_at`.

| Method and path | Body | What it does |
| --- | --- | --- |
| `POST /api/v1/views` | `list_key`, `name`, optional `visibility` (`private`), `conditions`, `sort` (`{field, dir}`, at most 3), `columns` (column keys or `{key, width}`; `null` for the list's default), `density`, `group_by` (what the rows are grouped by, on a list that groups them, such as `person` on `work.time_report`), `pinned` (the quick filters shown as chips under the tabs, keys of the list's fields in order, at most 4; `[]` pins none, `null` the list's own), `show_count`, `placement` | Creates a view, `201` with its `Location`. Any owner or member may share one. |
| `PATCH /api/v1/views/{viewId}` | Any of the fields above but `list_key`, plus `position` and `hidden` | Changes the fields sent; `conditions`, `sort` and `columns` replace what the view had. A shared view is changed by its creator and the owners, a view a module ships by the owners only, who also hide it with `hidden`. |
| `DELETE /api/v1/views/{viewId}` | none | Deletes a view for everyone. A view a module ships is refused with `409 default_view`. |
| `POST /api/v1/views/restore-defaults` | `list_key` | Brings the views a module ships on a list back to what it declares. Owners only. |

The writes take an `Idempotency-Key` (see [Idempotency](#idempotency)) and are in the audit log. A condition that cannot be used is answered `400 invalid_parameter` with `parameter` naming its path, such as `conditions[1].value.relative`, and `reason` `filter_invalid`; a person who may not change a view gets `403 forbidden`.

Rows as a view shows them, with only its columns, are not served yet; list the records of a view through the endpoint of its list, such as `GET /api/v1/invoices?view=`.

## Contact writes

These endpoints need the `contacts:write` scope. Each answers `{"data": {...}}` with the contact as `GET /api/v1/contacts/{contactId}` shows it; for a link or an unlink, that is the company with its contact persons. Bodies are JSON (`Content-Type: application/json`) with snake case fields. The rules are those of the address book screens, and the owners and team members of the organisation may write.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/contacts` | `201` with the new contact and `Location`. |
| `PATCH /api/v1/contacts/{contactId}` | The contact as it is now. |
| `POST /api/v1/contacts/{contactId}/archive` | The archived contact. Archiving it again changes nothing. |
| `POST /api/v1/contacts/{contactId}/restore` | The restored contact. Restoring it again changes nothing. |
| `POST /api/v1/contacts/{contactId}/contact-persons` | The company with the person linked. |
| `DELETE /api/v1/contacts/{contactId}/contact-persons/{personId}` | The company without the link. Both contacts stay. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 5b0e1c2a-create-alpha" \
  -d '{"kind": "company", "display_name": "Alpha Example s.r.o.", "registration_number": "12345679", "email": "alpha@example.com", "roles": ["customer"], "street": "Example Street 1", "city": "Example City", "postal_code": "100 00", "country_code": "CZ"}' \
  "https://app.example.com/api/v1/contacts"
```

Fields of a contact:

| Field | Value |
| --- | --- |
| `kind` | Required on create. `person`, `sole_trader` or `company`. It does not change later. |
| `display_name` | Required. At most 255 characters. |
| `street`, `city`, `postal_code`, `country_code` | Required. The billing address; every contact has one. The country is ISO 3166-1 alpha-2, such as `DE`. |
| `registration_number` | The company registration number, checked by the rules of its country. A company needs it or a `vat_id`. |
| `registration_country` | The country that issued the number. The country of the billing address when left out. |
| `vat_id` | Two letters of the country, then the number. |
| `email` | The general e-mail address. |
| `phone`, `mobile`, `website` | Text. |
| `roles` | A list of `customer`, `supplier`, `partner` and `authority`. An update replaces the list. |
| `private_note` | A note for the staff, never printed. |

`PATCH` changes only the fields sent. A field left out keeps its value; an optional field sent as `null` is cleared. The fields of a link are `person_id` (a contact of kind `person`), `position` (at most 100 characters) and `primary` (`true` makes it the company's primary contact person, in place of the previous one).

What a contact write refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field breaks a rule. `parameter` names it and `reason` says which rule: `required`, `too_long`, `kind_invalid`, `role_invalid`, `country_unsupported`, `email_invalid`, `registration_number_invalid`, `vat_id_invalid`, `company_identifier_required` or `person_invalid`. |
| 404 | `not_found` | The organisation has no such contact. |
| 404 | `not_linked` | The person is not linked to the company. |
| 409 | `duplicate` | Another contact has the registration number or the VAT id. `existing_ids` lists the contacts it matched. |
| 409 | `already_linked` | The person is linked to the company already. |

There is no upsert. To keep a contact you may have created before, search for it first (`q` also matches e-mails), and on `duplicate` read the contact in `existing_ids` and update it.

## Firm VAT statuses

The VAT status of the firm over time: each status holds from its day (`valid_from`) until the next one begins. `GET /api/v1/firm` lists them in `vat_statuses`, oldest first, each `{id, status, period_length, valid_from}`, and keeps `vat_status` as the status that holds today. `status` is `registered` (a VAT payer), `not_registered` or `identified_person`. `period_length` is `month` or `quarter` for a registered payer, and `null` for every other status. `valid_from` is `null` only for a status that held from the start, before the statuses had days: the status the firm had before this was added, or one set up without a day. Add a change with its day, so the day VAT is counted from is known.

The writes need the `contacts:write` scope, and only the **owner** of the organisation may use them, as the firm's settings: anyone else is refused with `403 owner_only`. Bodies are JSON with snake case fields.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/firm/vat-statuses` | `201` with the status: `id`, `status`, `period_length` and `valid_from`, which is required. |
| `DELETE /api/v1/firm/vat-statuses/{vatStatusId}` | `{"id": ..., "removed": true}`. The status before it holds again. A `vat_payer` status (its id starts with `firm-vat:`) is `400 invalid_parameter` with the reason `vat_payer_on_firm`. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"status": "registered", "period_length": "month", "valid_from": "2026-11-12"}' \
  "https://app.example.com/api/v1/firm/vat-statuses"
```

After every change, and each night after midnight in the instance's time zone, the firm takes the status that holds that day: the documents it issues follow it. Every change records the event `party.firm.vat_status_changed`, version 1, with the facts `what` (`added` or `removed`), `status`, `period_length` and `valid_from` of that status, and the firm as its subject.

What a write refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field breaks a rule. `parameter` names it and `reason` says which rule: `status_invalid`, `period_length_required` (a registered payer without one), `period_length_not_allowed` (a length on another status), `period_length_invalid` or `date_invalid`. |
| 403 | `owner_only` | The token's person is not the owner. |
| 404 | `not_found` | The firm has no such status, or the instance has no firm yet. |
| 409 | `same_day` | A status already starts on that day. Remove it first. |
| 409 | `vat_id_missing` | The status of today would need a VAT ID (a payer or an identified person), and the firm has none. |
| 409 | `last_stretch` | The firm keeps at least one status: add the new one first. |

## Work writes

The writes of projects need `projects:write`, those of tasks and comments `tasks:write`, those of time `time_entries:write`. Each runs the use case of the screens, so the person of the token may do over the API what they may do there and no more. Bodies are JSON with snake case fields.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/tasks` | `201` with the task as `GET /api/v1/tasks/{taskId}` shows it, and `Location`. |
| `PATCH /api/v1/tasks/{taskId}` | The task as it is now. |
| `POST /api/v1/tasks/{taskId}/trash` | `{"id", "trashed": true}`. The task and its subtasks go to the trash for 30 days. |
| `POST /api/v1/tasks/{taskId}/restore` | The task out of the trash. |
| `POST /api/v1/tasks/{taskId}/comments` | `201` with the comment. |
| `PATCH /api/v1/task-comments/{commentId}` | The comment with its new text. Only its author edits it. |
| `POST /api/v1/task-comments/{commentId}/trash` | `{"id", "task_id", "deleted": true}`. Its author or an owner restores it on the screens for 30 days. |
| `POST /api/v1/projects` | `201` with the project as `GET /api/v1/projects/{projectId}` shows it, and `Location`. |
| `PATCH /api/v1/projects/{projectId}` | The project as it is now. |
| `POST /api/v1/projects/{projectId}/archive` | The archived project. |
| `POST /api/v1/projects/{projectId}/restore` | The project, active again. |
| `POST /api/v1/time-entries` | `201` with the entry. |
| `POST /api/v1/time-entries/timer` | `201` with the running entry. A timer that ran already is stopped and saved first. |
| `POST /api/v1/time-entries/{entryId}/stop` | The stopped entry. The entry must be the person's running timer. |

Fields of a task: `project_id` (required on create, it does not change), `title` (required, at most 500 characters), `description` (Markdown), `section_id`, `parent_id` (create only: a subtask of a task of the same project), `status_id` (a status of its project; the first open one when left out), `responsible_id` (a person from `GET /api/v1/projects/{projectId}/people`), `due_on` (`YYYY-MM-DD`), `priority` (`none`, `low`, `medium`, `high` or `urgent`) and `label_ids` (it replaces the labels of the task). `PATCH` changes only the fields sent; `responsible_id`, `due_on` and `section_id` sent as `null` are cleared. A `PATCH` is saved whole or not at all. To complete a task, set `status_id` to a status of type `done` or `closed`; to reopen it, to one of type `open`. A new responsible is told, and the watchers hear of a new status, as on the screens.

```bash
curl -X PATCH -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"status_id": "0193a1b2-0000-7000-8000-000000000003"}' \
  "https://app.example.com/api/v1/tasks/0193a1b2-0000-7000-8000-000000000010"
```

A comment takes `body` (Markdown, at most 20,000 characters) and `internal` (`true` keeps it to the people of the organisation). A project takes `name` (at most 200 characters), `client_id` (a contact), `description` and, on create, `workflow` (`simple` or `development`); its sharing and sections are kept on the screens. Time takes `project_id`, `task_id`, `worked_on` (`YYYY-MM-DD`), `minutes` or `started_at` and `ended_at` (ISO 8601 with the offset) but not both, `note` (at most 2,000 characters) and `billable` (the default of the project when left out); a timer takes `project_id`, `task_id`, `note` and `billable`.

What a work write refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field breaks a rule. `parameter` names it and `reason` says which: `name`, `date`, `client`, `parent`, `responsible`, `comment_empty`, `comment_too_long`, `duration`, `time_note` or `time_task`. |
| 403 | `forbidden` | Only the owners and team members do this, such as starting a project. |
| 403 | `task_not_yours`, `comment_not_yours`, `time_not_yours` | Only who created it, or an owner, may. |
| 404 | `not_found` | No such project, section, status, task, label, comment or entry the person sees. |
| 409 | `archived` | The project is archived. |
| 409 | `parent_in_trash`, `comment_deleted`, `time_invoiced`, `timer_running`, `timer_not_running` | The record is in a state that does not allow it. |

## Cash flow

The cash flow of the firm: planned items, bank balance checkpoints, the expected days of documents, the minimum balance and the forecast. What each one means is in [cash-flow.md](cash-flow.md). The writes need `cashflow:write`, the reads `cashflow:read`, and the owners and team members of the organisation may use them. Bodies are JSON with snake case fields.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/cash-flow/planned-items` | `201` with the new planned item and `Location`. |
| `PATCH /api/v1/cash-flow/planned-items/{plannedItemId}` | The item as it is now. A field left out keeps its value; an optional field sent as `null` is cleared, so `"repeat": null` makes it one-off. The firm and the direction do not change. |
| `POST /api/v1/cash-flow/planned-items/{plannedItemId}/skip` | The item with the day `on` among `skipped_on`. The day must be one the item falls on. |
| `POST /api/v1/cash-flow/planned-items/{plannedItemId}/end` | The recurring item ending on the day `on`. A one-off item is refused with `409 not_recurring`. |
| `DELETE /api/v1/cash-flow/planned-items/{plannedItemId}` | `{"id": ..., "deleted": true}`. The item and its occurrences are gone. Refused with `409 has_pairings` while a document is paired with one of its occurrences (`auto` or `confirmed`); end a recurring item instead. |
| `POST /api/v1/cash-flow/pairings` | The pairing of the document (`subject_type`, `subject_id`) with the occurrence `occurrence_on` of `planned_item_id`, `confirmed`. Refused with `400 not_an_occurrence` or `400 direction_mismatch`, and `404` for an unknown item or document. The occurrence chosen is always among the row's `candidates`. Records `cash-flow.document_pairing.changed` when the state, the item or the day changed. |
| `DELETE /api/v1/cash-flow/pairings/{subjectType}/{subjectId}` | The pairing, now `declined`: Cash flow never pairs the document on its own again. The candidates are kept. `404` for a document with no pairing. |
| `POST /api/v1/cash-flow/checkpoints` | `201` with the checkpoint: `bank_account_id`, `on` (today or before) and `amount` in the currency of the account. The same account and day again corrects it. |
| `POST /api/v1/cash-flow/expected-dates` | The expected day: `subject_type` and `subject_id` as a forecast item names its subject, and `expected_on`. Setting it again moves it. |
| `DELETE /api/v1/cash-flow/expected-dates/{subjectType}/{subjectId}` | The subject with `expected_on: null`. Clearing one that is not set changes nothing. |
| `POST /api/v1/cash-flow/minimum-balance` | The minimum balance in CZK (`amount`); `null` removes it. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"direction": "out", "name": "Office rent", "category": "rent", "amount": "15000.00", "on": "2026-12-15", "repeat": "month"}' \
  "https://app.example.com/api/v1/cash-flow/planned-items"

curl -H "Authorization: Bearer $FORESI_API_TOKEN" \
  "https://app.example.com/api/v1/cash-flow/forecast?months=6"
```

Fields of a planned item:

| Field | Value |
| --- | --- |
| `direction` | Required on create. `in` or `out`. |
| `name` | Required. At most 120 characters. |
| `category` | Required. `revenue`, `purchases`, `rent`, `software`, `payroll`, `taxes`, `insurance`, `vat`, `loans`, `owner_draws` or `other`. |
| `amount` | Required. The amount of one occurrence, more than zero, as a decimal string such as `"1500.00"`. |
| `currency` | `CZK` (the default), `EUR` or `USD`. |
| `on` | Required. The day of a one-off item, or the first day of a recurring one. |
| `repeat` | `day`, `week`, `month` or `year`; left out or `null` for a one-off item. |
| `every` | 1 to 99, 1 by default. |
| `last_day_of_month` | `true` puts a monthly or yearly item on the last day of its month. |
| `ends_on`, `ends_after` | The last day an occurrence may fall on, or a count of 1 to 999 occurrences; not both. |
| `counterparty_id` | A contact the money comes from or goes to. |
| `included` | `false` leaves the item out of the forecast; `true` by default. |
| `match_account`, `match_variable_symbol`, `match_tolerance` | The counterparty account, a variable symbol of up to 10 digits and how far a payment may differ, a bank movement that meets them replaces the planned occurrence of its period. |
| `vat_mode`, `vat_rate` | How the amount stands to its VAT: `none` (the default), `included`, `excluded` or `reverse_charge`, with a rate from 0 to 100 with at most two decimals, such as `"21"`. `reverse_charge` is for money out whose VAT the firm self-assesses as the recipient (a domestic reverse charge, a service from the EU or from outside it): the amount moves as stated and the VAT counts as both output and input in the VAT period. Money in refuses it (`vat_mode_reverse_charge_in`). The VAT counts only on the days the firm is a VAT payer. A rate needs a mode other than `none` (`vat_rate_without_mode`), which needs a rate (`vat_rate_required`); a rate out of range is `vat_rate_invalid`. A change to `none` clears the rate. |

A planned item answers these fields with `id`, `firm`, `skipped_on` and `created_at`, and also `net` and `vat` (one occurrence as stated, the VAT self-assessed with `reverse_charge`, both `null` with `vat_mode` `none`) and `vat_missing`, `true` while the firm is a VAT payer and the item states no VAT, but for the categories `vat`, `taxes`, `insurance`, `loans` and `owner_draws`.

Query parameters of `GET /api/v1/cash-flow/forecast`:

| Parameter | Value |
| --- | --- |
| `from` | The first day, today or later. Today by default. |
| `months` | How many calendar months the forecast covers, 1 to 24. 12 by default. |
| `weeks_until` | Weeks are used up to the week that holds this day, then months. The end of next month by default. |
| `certainty` | The certainties counted, separated by commas: `document`, `schedule`, `plan`, `estimate`. All by default. The balances, `lowest` and `shortfall_on` are then those of the chosen flows; the balance today is never filtered. |

The forecast answers `firm`, `currency` (`CZK`), `today`, `from`, `to`, `certainty` (the certainties counted); `balance` with its `amount`, `as_of`, `stale` and the `accounts` (each with its id, IBAN, balance in its own currency, the amount in CZK, `as_of` and the `source` that knew it); `periods`, each with `key` (`2026-W47` or `2027-01`), `kind`, `start`, `end`, `opening`, `inflows` and `outflows` (`total`, `by_category` and `by_certainty`), `closing` and its `items`; `lowest` (`amount` and `on`), `minimum_balance`, `shortfall_on`, `incomplete`, `unavailable_sources`, `unavailable_rates` and `restricted_sources`. An item has its `source`, `subject` (`type`, `id`, `url`), `direction`, `label`, `category`, `certainty`, `flags` (`overdue`, `awaited`, `expected_date`, `estimated`, `no_due_date`; an `awaited` flow is past its date but not late, counted on the first day without `overdue`), `expected_on`, `counted_on`, `amount` in its own currency, `amount_czk` and `counterparty_id`.

The items come from the sources of the installed modules: planned items, issued and recurring invoices, received documents. A planned occurrence that a document or a bank movement already stands for is left out. A source the token may not read in detail still counts in the totals and the balance: `cashflow:read` alone shows no invoices without `invoices:read` and no received documents without `received_documents:read`. Such a source is named in `restricted_sources`, and each period lists its flows in `hidden_items`, each with `source`, `direction`, `category`, `amount_czk` and the `count` of items. See [cash-flow.md](cash-flow.md#sources).

What the cash flow refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field or parameter breaks a rule. `parameter` names it and `reason` says which rule, such as `name_required`, `amount_invalid`, `amount_not_positive`, `date_invalid`, `date_in_future`, `repeat_unknown`, `recurrence_invalid`, `end_ambiguous`, `not_recurring`, `not_an_occurrence`, `ends_before_start`, `counterparty_unknown`, `from_in_past`, `months_invalid` or `invalid_certainty`. |
| 403 | `forbidden` | A guest of the organisation. |
| 404 | `not_found` | The instance has no firm yet, or the organisation has no such bank account or planned item. |
| 409 | `not_recurring` | Ending a one-off item. |

## Calendar

Your own calendar feeds (see [calendar](calendar.md)): secret iCal addresses for your calendar apps. Reading needs `calendar:read`, writing `calendar:write`, and only the owners and members of the organisation have feeds. Each person sees and changes only their own; someone else's feed is `404 not_found`. A feed has `id`, `name`, `created_at`, `last_fetched_at` (recorded at most once an hour) and `revoked_at`.

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/calendar/feeds` | Your feeds, revoked ones too, never their addresses. |
| `POST /api/v1/calendar/feeds` | `201` with the feed and, this once, its `address` (https) and `webcal`. Only the hash of the token is kept. A name of 1 to 80 characters is required (`name_invalid`). |
| `PATCH /api/v1/calendar/feeds/{feedId}` | The feed with its new `name`; the address stays. |
| `POST /api/v1/calendar/feeds/{feedId}/revoke` | The feed with `revoked_at`. Its address answers `404` from now on and cannot be brought back. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"name": "Phone"}' "https://app.example.com/api/v1/calendar/feeds"
```

## Mail addresses

Your own inbound mail addresses (see [mail](mail.md)): secret e-mail addresses that take mail in for one purpose, such as the receipts of a module that reads them. Reading needs `mail_addresses:read`, writing `mail_addresses:write`, and only the owners and members of the organisation have addresses. Each person sees and changes only their own, one active address per purpose; someone else's address is `404 not_found`. An address has `id`, `purpose`, `label`, `masked_address` (the address with its secret part left out), `created_at`, `revoked_at`, `last_received_at`, and `limit_reached_today` with `dropped_today` (the e-mails dropped today after the address took its daily limit).

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/mail/addresses` | Your addresses, revoked ones too, never their tokens; `purpose` filters them. |
| `POST /api/v1/mail/addresses` | `201` with the address and, this once, its full `address`; the answer is sent with `Cache-Control: no-store, private`. Body: `purpose` and `label` (1 to 80 characters). Refused: `purpose_unknown`, `purpose_has_no_owners` (a bank feed is managed on its bank account), `label_required`, `label_too_long` (400), `address_exists` (409, you have an active address for the purpose), `inbound_mail_not_configured` and `inbound_mail_no_key` (503, the operator has to set them up). |
| `PATCH /api/v1/mail/addresses/{addressId}` | The address with its new `label`; the address itself stays. |
| `DELETE /api/v1/mail/addresses/{addressId}` | The address with `revoked_at`. It takes in nothing from now on and stays listed. Revoking it again is `409 address_revoked`. |
| `POST /api/v1/mail/addresses/{addressId}/replace` | `201` with a new address of the same purpose and label, its full `address` shown this once (`Cache-Control: no-store, private`); the old one is revoked in the same step, and kept as it was when the new one cannot be made. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"purpose": "<purpose key>", "label": "Receipts"}' "https://app.example.com/api/v1/mail/addresses"
```

## Catalog

The products of the catalog: what the firm sells, with its unit, an optional price per unit and how it is billed. What each field means is in [catalog.md](catalog.md). The writes need `catalog:write`, the reads `catalog:read`; only the owners and members of the organisation may use them, never a guest. Bodies are JSON with snake case fields.

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/catalog/products` | `201` with the new product and `Location`. |
| `PATCH /api/v1/catalog/products/{productId}` | The product as it is now. A field left out keeps its value; an optional field sent as `null` is cleared, so `"unit_price": null` leaves it without a price. Making it `one_off` clears its period. |
| `POST /api/v1/catalog/products/{productId}/archive` | The archived product. Archiving it again changes nothing. |
| `POST /api/v1/catalog/products/{productId}/restore` | The product, no longer archived. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"name": "Marketing retainer", "billing_kind": "recurring", "unit": "month", "unit_price": "25000.00", "recurrence_unit": "month", "interval": 1, "cycles": 12}' \
  "https://app.example.com/api/v1/catalog/products"
```

Fields of a product:

| Field | Value |
| --- | --- |
| `name` | Required on create, up to 200 characters. |
| `billing_kind` | `one_off` (the default), `recurring` or `time_based`, billed by the hour. |
| `unit` | Up to 32 characters, as the unit of an invoice line; may be empty. `h` for `time_based` (empty means `h`, another unit is refused `time_based_unit`). |
| `unit_price` | A decimal of zero or more, without VAT, or `null` when the price is agreed each time. Required for `time_based`, where it is the hourly rate (`rate_required`). |
| `currency` | `CZK` (the default), `EUR` or `USD`. |
| `recurrence_unit` | `day`, `week`, `month` or `year`; required for `recurring`, refused for `one_off` and `time_based`. |
| `interval` | 1 to 99, required for `recurring`: billed every `interval` units. Refused for `one_off` and `time_based`. |
| `cycles` | 1 to 999 times, or `null` for no end. Refused for `one_off` and `time_based`. |
| `description` | Up to 2,000 characters. |

A refused field is answered `400 invalid_parameter` with the `parameter` and a `reason`, such as `interval_required`, `one_off_recurrence`, `time_based_recurrence` or `cycles_invalid`.

## Sales

The deals of the firm with their stages, lines and links. What each field means is in [sales.md](sales.md). The reads need `sales:read`, the writes `sales:write`; only the owners and members of the organisation may use them, never a guest. Bodies are JSON with snake case fields.

Reads:

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/sales/deals` | The deals not in the trash, newest first, each with its `status`, `client`, `owner_user_id`, `stage`, `won_at`, `lost_at` and `lost_reason`. Filters: `status` (`open`, `won`, `lost`), `stage_id`, `owner_user_id`, `client_party_id` (the deals of a client) and `q` (a text in the title). |
| `GET /api/v1/sales/deals/{dealId}` | One deal, also one in the trash, with its `contacts`, `lines`, `value` and `links`. `value` holds `one_off`, `monthly`, `hourly` and `approximate`: the first two are one amount per currency and never converted, `approximate` is true when a weekly or daily line was put on a month's terms, and `hourly` lists each `time_based` line with its `rate`, `expected_hours` and `expected_amount` (the last two `null` without expected hours), never added into the others. A `follows_from` link is outgoing when this deal follows from the other. |
| `GET /api/v1/sales/stages` | The stages in their order; archived ones with `include_archived=true`. |
| `GET /api/v1/sales/lost-reasons` | The lost reasons in their order; archived ones with `include_archived=true`. |

Writes, each an operation that answers the deal as `GET /api/v1/sales/deals/{dealId}` shows it unless it says otherwise:

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/sales/deals` | `201` with the new deal: `title`, `client_party_id`, and optionally `stage_id`, `owner_user_id`, `description` and `contact_person_ids`. The first stage and the caller are the defaults. |
| `PATCH /api/v1/sales/deals/{dealId}` | The deal with what was given changed: `title`, `description`, `owner_user_id`, `client_party_id`, `contact_person_ids` (the whole list; an empty list removes them all). Another client takes the `contact_person_ids` of the same call, checked against it, or none; an archived client is refused with `client_archived`, and a deal linked to other deals with `client_has_links` (`409`) until its links are removed. |
| `POST /api/v1/sales/deals/{dealId}/stage` | An open deal moved to `stage_id`. A won or lost deal is refused with `deal_not_open`. |
| `POST /api/v1/sales/deals/{dealId}/win` | The open deal won. |
| `POST /api/v1/sales/deals/{dealId}/lose` | The open deal lost, with an optional `lost_reason_id` and `note`. |
| `POST /api/v1/sales/deals/{dealId}/reopen` | The won or lost deal open again. |
| `POST /api/v1/sales/deals/{dealId}/trash` | The deal in the trash. Trashing it again changes nothing. |
| `POST /api/v1/sales/deals/{dealId}/restore` | The deal back from the trash. |
| `POST /api/v1/sales/deals/{dealId}/follow-ups` | `201` with a new deal for the same client and contact persons that follows from this one: `title`, optionally `line_ids` to copy as they are, `stage_id` and `owner_user_id`. |
| `POST /api/v1/sales/deals/{dealId}/links` | `201` with a link: `to_deal_id`, the deal it follows from, and `type` (`follows_from`). |
| `DELETE /api/v1/sales/deals/{dealId}/links/{linkId}` | The link removed; both deals stay. |
| `POST /api/v1/sales/deals/{dealId}/lines` | `201` with a line: a `product_id` of the catalog and `quantity`, or a typed `name`, `unit`, `unit_price`, `currency`, `billing_kind`, `recurrence_unit`, `interval` and `cycles`, as in [Catalog](#catalog). A `time_based` line has the unit `h` (`time_based_unit` for another), its `unit_price` is the hourly rate (`rate_required` without one), `quantity` is the optional hours expected, and it has no recurrence (`time_based_recurrence`). |
| `PATCH /api/v1/sales/deals/{dealId}/lines/{lineId}` | The line with what was given changed; `unit_price: null` leaves it without a price, and making it `one_off` or `time_based` clears its period. The product it was copied from does not change. |
| `DELETE /api/v1/sales/deals/{dealId}/lines/{lineId}` | The line removed; the lines after it move up. |
| `POST /api/v1/sales/deals/{dealId}/lines/reorder` | The lines in the order given: `line_ids`, naming every line once. |
| `POST /api/v1/sales/stages` | `201` with a stage at the end of the order: `name` and an optional `key`, a lowercase identifier that never changes. |
| `PATCH /api/v1/sales/stages/{stageId}` | The stage renamed; a stage Foresi seeded may be given `null` to show under its own name again. |
| `POST /api/v1/sales/stages/reorder` | The stages in the order given: `stage_ids`, naming every stage that is not archived once. |
| `POST /api/v1/sales/stages/{stageId}/archive` | The archived stage, or `stage_has_open_deals` while open deals sit in it. |
| `POST /api/v1/sales/lost-reasons` | `201` with a lost reason at the end of the list: `name` and an optional `key`. |
| `PATCH /api/v1/sales/lost-reasons/{reasonId}` | The lost reason renamed, as a stage. |
| `POST /api/v1/sales/lost-reasons/{reasonId}/archive` | The archived lost reason; the deals lost for it keep it. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" -H "Idempotency-Key: $(uuidgen)" \
  -d '{"title": "Roof audit for the warehouse", "client_party_id": "'$CLIENT_ID'"}' \
  "https://app.example.com/api/v1/sales/deals"
```

A refused field is answered `400 invalid_parameter` with the `parameter` and a `reason`; a refused state change is answered with a code such as `deal_not_open`, `deal_not_closed`, `link_self`, `link_other_client`, `link_duplicate`, `client_archived`, `contact_person_not_of_client` or `stage_has_open_deals`.

## Taxes and contributions

The owner profile the taxes and contributions are estimated from: the dated statuses, the inputs of each year and the authority accounts. What each one means is in [taxes-and-contributions.md](taxes-and-contributions.md). The writes need `taxes:write`, the read `taxes:read`, and only the **owner** of the organisation may use them: anyone else is refused with `403 owner_only`. Bodies are JSON with snake case fields, amounts in CZK as decimals such as `"12500.00"`.

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/taxes-and-contributions/profile` | `{statuses, years, authorities}`. |
| `GET /api/v1/taxes-and-contributions/amounts?year=2026` | The actual income and costs of the year to date in CZK: `totals` and `months` (by month, `kind` and `source`) with `gross`, `net`, `vat` and `counted`, the payments themselves in `amounts`, and `unavailable_sources`, `restricted_sources`, `missing_rates`, `unverified` and `known_gaps`. Without `year`, this year. A year after this one or before 2000 is `400 invalid_parameter` with the reason `invalid_year`. |
| `GET /api/v1/taxes-and-contributions/estimate?year=2026` | The estimate of the year: `to_date` and `year_end` apart, each method with its totals and steps, the `forecast` part, `accruals`, and the `payments` of the year by due day, each with its `authority`, `kind` (`advance`, `settlement`, `overpayment`), `period`, `due_on`, `amount`, `state` (`expected`, `paid`, `overdue`), `match_rule` and the bank movement in `paid_by`. `possible_duplicates` names the planned items for taxes and insurance it may count twice. Without `year`, this year; from 2000 to next year. See [Payments](taxes-and-contributions.md#payments). |
| `POST /api/v1/taxes-and-contributions/profile/statuses` | `201` with the status: `kind`, `value`, `valid_from` and, for sickness insurance that is on, `base`. A second status of one kind on one day is `409 status_day_taken`. The kind `vat_payer` is `400 invalid_parameter` with the reason `vat_payer_on_firm`: it follows the [VAT statuses of the firm](#firm-vat-statuses). |
| `DELETE /api/v1/taxes-and-contributions/profile/statuses/{statusId}` | `{"id": ..., "removed": true}`. The status before it holds again. A `vat_payer` status (its id starts with `firm-vat:`) is `400 invalid_parameter` with the reason `vat_payer_on_firm`. |
| `PUT /api/v1/taxes-and-contributions/profile/years/{year}` | The inputs of the year as they are now. The whole year is replaced: a field left out is cleared. `previous_activity_months` (1 to 12, or `null`) is the months of the previous year with an activity, which divide its bases for the advances; `null` counts the activity statuses of that year, or twelve, flagged `previous_activity_months`. Out of range is `400 invalid_parameter` with the reason `activity_months_invalid`. It reads back as `previous_year.activity_months`. |
| `PUT /api/v1/taxes-and-contributions/profile/authorities/{authority}` | The account of `social`, `health`, `sickness` or `income_tax`: `account` and `variable_symbol`. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"kind": "activity", "value": "main", "valid_from": "2026-07-01"}' \
  "https://app.example.com/api/v1/taxes-and-contributions/profile/statuses"
```

## VAT

The VAT periods of the firm, the bank payments that settle them, and where it pays its VAT. What a period is, how its dates come about and how a payment finds its period is in [vat.md](vat.md). The reads need `vat:read`, the writes `vat:write`, and the owners and members of the organisation may use them: a guest's token is never accepted. Every answer about periods is an estimate: it carries `estimate: true`, `unverified` (the rules nobody has confirmed yet) and `known_gaps` (what the amounts do not count yet).

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/vat/periods?from=2026-11&to=2027-12` | The periods from the day the firm became a payer, oldest first, each with `key` (`2026-11` or `2026-Q4`), `length`, `start` and `end` (clipped to the days the firm is a payer), `due_on`, `refund_expected_on`, `output`, `input`, `result` (positive to pay, negative to get back), `breakdown` (`documents`, `schedules`, `plans`, each with `output` and `input`), `steps` (`output`, `input` and `result`, each with its `amount`, `rule` and `sources`), `warnings` (`missing_czk_vat`) and `missing_czk` (the documents counted without a CZK amount of their VAT); and `reason`, `country`, `from`, `to`, `warnings`, `unverified`, `known_gaps` and `unavailable_sources` (the document sources not installed or not readable, `invoicing.issued_vat` and `purchasing.received_vat`). `from` and `to` are months or quarters; without `from` the list starts with the first period, without `to` it ends with the period that holds the twelfth month after this one. A `to` more than 24 months ahead is `400 invalid_parameter` with the reason `range_too_long`; a month that cannot be read is `from_invalid` or `to_invalid`, and a `from` after `to` is `range_invalid`. With nothing to compute, `periods` is empty and `reason` is `payer_date_missing`, `not_a_payer` or `country_not_supported`. The amounts count the documents, the recurring invoices and the plans (see [vat.md](vat.md#amounts)). Each period also has `return_amount` (entered by a person, `null` until then), `expected` (the return amount, else the result), `state` (`estimated`, `return_entered`, `settled` or `not_yet_paid`; never overdue), `payments` (the bank movements that pay it, each with `movement_id`, `on`, `amount`, `direction`, `rule`, `period`, `manual` and `unverified`), `paid` and `difference` (paid minus expected, information only), both `null` without a payment, and `settled_mark` (`at`, `note`) when a person marked it settled. The list has `unassigned_payments`, the movements to or from the tax office that pay no period, and `possible_duplicates`, the planned items of the category `vat` (`id`, `name`, `url`). |
| `GET /api/v1/vat/periods/{key}` | The period `key` (`2026-11` or `2026-Q4`) as in the list, under `period`, with the same `reason`, `country`, `unverified`, `known_gaps` and `unavailable_sources`, and `documents`: each counted document with `source`, `subject` (`type`, `id`, `url`), `label`, `counts_on`, `tax_point_on`, `output`, `input` and `missing_czk`. With them come `schedules` (the recurring invoices not issued yet) and `plans`: each counted plan with `source` (`cash-flow.planned_vat`), `subject` (`planned_item`), `label`, `on`, `output` and `input`. The documents, schedules or plans of a source the token may not read (`invoices:read`, `received_documents:read`, `cashflow:read`) are left out and the source is listed in `restricted_sources`; they still count in the totals. A key that is no month or quarter is `400 invalid_parameter` with `key_invalid`, and a key the firm has no period for is `404`. |
| `GET /api/v1/vat/settings` | `{office_account, variable_symbol, suggestion}`: the account of the tax office and the variable symbol, `null` until they are set, and the country's `suggestion` (`account_hint`, `variable_symbol`, `source_url`), `null` without its rules. |
| `PUT /api/v1/vat/settings` | Replaces them: `office_account` (required, up to 64 characters) and `variable_symbol` (up to 35; empty takes the suggested one). Answers as the read. |
| `PUT /api/v1/vat/periods/{key}/return-amount` | Enters the amount of the filed return, `amount` in CZK (a decimal such as `"1500.00"`, negative for a refund), which replaces the estimate as `expected`. Answers as `GET /api/v1/vat/periods/{key}`. An amount that cannot be read is `400 invalid_parameter` with `amount_invalid`. |
| `DELETE /api/v1/vat/periods/{key}/return-amount` | Removes it, so the estimate counts again; `404` when there is none. |
| `POST /api/v1/vat/periods/{key}/settle` | Marks the period settled by hand, with an optional `note` (up to 500 characters, else `note_too_long`), such as one paid from an account the bank does not show. It has no flow in the forecast any more. |
| `DELETE /api/v1/vat/periods/{key}/settle` | Removes the mark; `404` when there is none. A period a bank payment pays stays settled. |
| `PUT /api/v1/vat/payments/{movementId}` | Places a bank movement: `period` is the key of the period it pays, `null` when it is no VAT payment. It wins over the automatic matching. Answers `{movement_id, period, automatic}`. |
| `DELETE /api/v1/vat/payments/{movementId}` | Gives the movement back to the automatic matching; `404` when nobody placed it. |

A period the firm does not have is `404 not_found`, and a key that is no month or quarter `400 invalid_parameter` with `key_invalid` (named `period` in a payment's body). Every write records the event `vat.period.changed`, version 1, with the facts `what` (`return_amount`, `settled`, `reopened`, `payment_assigned` or `payment_automatic`), `period` and `movement` (the id of the bank movement of a payment), and the period as its subject, or the movement when it pays none.

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" "https://app.example.com/api/v1/vat/periods?from=2026-11&to=2027-03"
```

## Margin

Who bears the cost of a received document (what it means is in [margin.md](margin.md)): the firm itself (`own`) or one of its clients, a contact. The reads need `margin:read`, the writes `margin:write`; only the owners and the members of the organisation work with the margin, so a guest is refused. A recipient is the string `own` or the id of an existing, active contact.

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/margin?from=2026-10&to=2026-12` | The margin of the clients by month, in CZK (what it counts is in [margin.md](margin.md#the-client-margin-view)). `from` and `to` are months written `YYYY-MM`, at most 24 months apart; the last three months ending with the current one when left out. It has `months`, `rows`, `month_totals`, a `total` and `known_gaps` (`missing_exchange_rate` with the `document_ids` that have no rate yet). A row is one client with revenue or costs in the range, the highest revenue first, then `own` and `unassigned` (always present): `row` (`client:<contact id>`, `own` or `unassigned`), `kind`, `client_id`, `label`, a `cells` entry for every month (`month`, `revenue`, `costs`, `margin`, each `amount_minor` with `currency`, and `unrebilled`, a count) and its `total` (the same figures, `unrebilled` summed). |
| `GET /api/v1/margin/cell?row=own&month=2026-11` | The documents behind one cell: its `revenue`, `costs` and `margin`, the `revenue_items` (issued documents) and the `cost_items` (cost shares), each with `document_id`, `label`, `text` (the cost line, or `null`), `amount`, `source` (`revenue`, or `line`, `document`, `planned_item`, `supplier`, `unassigned`), `kind` (of a revenue document, or `null`), `url`, `planned_item_id` and `occurrence_on`. |
| `GET /api/v1/margin/unrebilled?from=2026-10&to=2026-12` | The costs paid and not rebilled (see [Rebilling](margin.md#rebilling)), earliest first, with the same `from` and `to` as the table. Each has `label` (the planned cost), `planned_item_id`, `planned_item_url`, `occurrence_on`, `month`, `recipient_id` (the client), `template_id`, `template_url` and `recurring_line_id`. Every cell and total of the table has `unrebilled`, the number of such costs, and the cell has them listed in `unrebilled`. |
| `PUT /api/v1/margin/recurring-lines/{recurringLineId}/rebills` | Body `{"planned_item_id": "<id>" \| null}`. Links a line of a recurring invoice template to the planned cost it rebills, or removes the link with `null`. Answers `recurring_line_id`, `planned_item_id`, `planned_item_name`, `planned_item_url`, `created_on` (the day of the link; costs of earlier months are never flagged) and `warnings` (`customer_not_a_recipient`: the customer bears no share of the cost's mapping this month, so no invoice would be paired). Needs `margin:write`. |
| `POST /api/v1/margin/rebills` | Body `{"invoice_line_id": "<id>", "planned_item_id": "<id>", "occurrence_on": "2026-12-05"}` (`occurrence_on` is any day of the occurrence's period; the occurrence that holds it is paired). `201` with the pairing of an issued invoice line with that occurrence: `invoice_line_id`, `invoice_id`, `planned_item_id`, `planned_item_name`, `planned_item_url`, `occurrence_on` and `manual` (`true`). Needs `margin:write`. |
| `DELETE /api/v1/margin/rebills/{invoiceLineId}` | Takes away the pairing of the line, one made by hand or on issue; answers `invoice_line_id` and `unpaired: true`. A pairing made by hand is removed when its invoice is trashed and not restored with it. Needs `margin:write`. |
| `GET /api/v1/margin/documents/{documentId}` | The recorded received document with `lines`: each has its `key`, `text`, `base_czk` (without VAT, `null` while a foreign document has no exchange rate), its own `allocation` (`kind` and `shares`, or `null`) and its `shares`, each with `recipient` (`own`, a contact id, or `null` while unassigned), `recipient_name`, `amount` in CZK and its `source` (`line`, `document`, `planned_item`, `supplier` or `unassigned`) and, for a `planned_item` share, the `planned_item_id` and the `occurrence_on` day it came from (both `null` otherwise). It also has `document_allocation` (the assignment of the whole document, or `null`), `trashed` and `known_gaps` (`missing_exchange_rate`). |
| `PUT /api/v1/margin/documents/{documentId}/lines/{lineKey}/allocation` | Replaces the mapping of one line and answers the document as the read does. `kind` is `whole` (one share), `percent` (two or more shares with a `percent` such as `"33.33"`, adding up to exactly 100) or `fixed` (shares with an `amount` in CZK such as `"800.00"`, and one share with `rest: true`); `kind: null` clears it. The shares are counted at once, in whole hellers: a percent is rounded down per share and the last share takes the remainder; a fixed amount is capped at what the line has left. A document without lines has one implicit line per VAT rate, keyed `rate:21`. |
| `POST /api/v1/margin/documents/{documentId}/assign` | Gives the whole document to `recipient`, for every line without a mapping of its own; `recipient: null` clears it. Answers as the read. |
| `GET /api/v1/margin/planned-items/{plannedItemId}/allocation` | The `versions` of the mapping of a recurring planned cost in [Cash flow](cash-flow.md), latest first, with the item's `planned_item_id` and `name`. Each version has `from_month` (`YYYY-MM`), its `allocation` (`kind` and `shares` as on a line, or `null` where the mapping ends), `current` (the version in force this month), `created_at` and `created_by`. |
| `PUT /api/v1/margin/planned-items/{plannedItemId}/allocation` | Body `{"allocation": {"kind": ..., "shares": [...]} \| null, "from_month": "2026-12"}`. Maps the documents paired with the item's occurrences from that month on, or with `allocation: null` ends the mapping from it. A month that has a version replaces it. The documents paired with occurrences from that month on are recalculated at once; earlier months keep their shares. Answers the versions as the read does. |
| `GET /api/v1/margin/suppliers/{contactId}/default-recipient` | The `versions` of the default recipient of a supplier, a contact, latest first, with `supplier_id` and `supplier_name`. Each has `from_month`, `recipient` (`own`, a contact id, or `null` where the default ends), `recipient_name` and `current`. |
| `PUT /api/v1/margin/suppliers/{contactId}/default-recipient` | Body `{"recipient": "own" \| "<contact id>" \| null, "from_month": "2026-12"}`. Gives the supplier's costs that nothing else claims to one recipient from that month on, never a split; `recipient: null` ends it. The supplier's documents whose cost month is that month or later are recalculated at once. Answers the versions as the read does. |

A document the margin has no costs for (unknown, or a draft) is `404 unknown_document`, and a line key the document does not have `404 unknown_line`. A planned item Cash flow does not know (or Cash flow is not installed) is `404 unknown_planned_item`, and a contact that is no active contact `404 unknown_supplier`. A line of a template, an invoice or a pairing that does not exist is `404 unknown_template_line`, `unknown_invoice_line` or `unknown_pairing`. A day that is not written `YYYY-MM-DD` is `400 invalid_parameter` with `invalid_day` (`parameter` is `occurrence_on`), a day no occurrence of the planned cost holds `not_an_occurrence`, a planned cost that is money coming in `not_an_expense`, a line that is paired already `already_paired` and a customer with no share of the cost that month `customer_not_a_recipient` (`parameter` is `invoice_line_id`). A month that is not written `YYYY-MM` is `400 invalid_parameter` with `invalid_month` (`parameter` is `from_month`, or `from`, `to` or `month` on the table and the cell); on the table `from` after `to` is `range_reversed` and more than 24 months `range_too_long`, and a cell `row` that is not `client:<contact id>`, `own` or `unassigned` is `invalid_row` (`parameter` is `row`); a planned item that is not a recurring cost, or has no counterparty, with `not_an_expense`, `not_recurring` or `counterparty_required` (`parameter` is `planned_item_id`). A client that is no active contact is `400 invalid_parameter` with `unknown_contact`, and a mapping that cannot be followed with `whole_needs_one`, `percent_must_add_up`, `fixed_needs_a_rest` or `duplicate_recipient` (`parameter` is `shares`; `recipient` in the assignment); `recipient_invalid`, `kind_invalid`, `share_invalid` and `amount_invalid` name a value that could not be read.

## Documents

Contracts and other documents made from templates, their signers and their signed copies. What the statuses mean and how manual signing works is in [documents.md](documents.md). The reads need `documents:read`, the writes `documents:write`, and only the owners and members of the organisation may use them: a guest's token is never accepted. Bodies are JSON with snake case fields.

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/documents` | The documents, newest first, each with `id`, `title`, `status`, `type`, the days it was created, generated and completed, `trashed` and `trashed_at`; those in the trash are left out unless `trashed=true`, which lists only them. Filters: `type_id`, `status` (`draft`, `ready`, `out_for_signature`, `completed`, `declined`, `cancelled`, `expired`), and `linked_kind` with `linked_id` for the documents of a record, such as `contact`. Paging: `limit` (1 to 200, 50 by default) and `offset`. A filter that cannot be used is `400 invalid_parameter`, naming it. |
| `GET /api/v1/documents/{documentId}` | The document with `template`, `links` (each with `available`, false when the record is gone), `signers` in signing order, `files` (`pdf`, `signed`, `certificate`, each `null` or `{attachment_id, sha256}`), the frozen `values`, `provider` and `envelope_id`. |
| `GET /api/v1/documents/{documentId}/files/{file}` | The bytes of the file (`pdf`, `signed` or `certificate`) as an attachment, `application/pdf`. `404 not_found` while the document has no such file. |
| `GET /api/v1/document-types` | The types by name; archived ones with `include_archived=true`. |
| `GET /api/v1/document-templates` | The templates by name, each with its latest version without the body; `type_id` and `include_archived=true` filter them. |
| `GET /api/v1/document-templates/{templateId}` | The template with its latest `version` (body, paper and the variables it `uses`), its `versions`, and every `variable` a body can use with its label and example. |
| `POST /api/v1/document-types` | `201` with the type: `name`, `code` (unique, never changes), `needs_signing`, `signature_level`. |
| `PATCH /api/v1/document-types/{typeId}` | The type with its new `name`, `needs_signing` and `signature_level`. |
| `POST /api/v1/document-types/{typeId}/archive` | The archived type. |
| `POST /api/v1/document-templates` | `201` with the template and its first version: `type_id`, `name`, `body`, and the paper (`paper`, `landscape`, `margin_top`, `margin_right`, `margin_bottom`, `margin_left` in whole millimetres). A body outside the sandbox is `400 invalid_parameter` with the reason `template_not_allowed`. |
| `POST /api/v1/document-templates/{templateId}/versions` | The template, its latest version edited in place, or version n+1 when the latest is frozen. |
| `POST /api/v1/document-templates/{templateId}/archive` | The archived template. |
| `POST /api/v1/documents` | `201` with the draft: `type_id`, `template_id`, `title` and `links`, a list of `{kind, id}`. A kind no module offers is `400 invalid_parameter` with the reason `link_kind_unknown`. |
| `PATCH /api/v1/documents/{documentId}` | The draft with its new `title` or `links`. |
| `POST /api/v1/documents/{documentId}/generate` | The ready document with its PDF and frozen values. A variable with no value is `400 invalid_parameter` with the reason `template_variables_missing` and the names in `missing`. |
| `POST /api/v1/documents/{documentId}/mark-sent` | The document out for signature. |
| `POST /api/v1/documents/{documentId}/signed-copy` | The document with its signed copy: `file_name` and `content_base64`, a PDF up to 10 MB. From ready it also marks it sent. |
| `POST /api/v1/documents/{documentId}/cancel` | The cancelled document. |
| `POST /api/v1/documents/{documentId}/trash` | The cancelled document, now in the trash; any other status is `409 not_cancelled`. Trashing it again changes nothing. |
| `POST /api/v1/documents/{documentId}/restore` | The document, back from the trash. |
| `POST /api/v1/documents/{documentId}/generate-again` | `201` with a new draft of a final document: the same type, links and signers, the latest template version. |
| `POST /api/v1/documents/{documentId}/signers` | `201` with the signer: `name`, `side` (`ours` or `theirs`), `order` (1 to 99), `email`, `party_person_id`. |
| `PATCH /api/v1/documents/{documentId}/signers/{signerId}` | The signer with its new fields. |
| `DELETE /api/v1/documents/{documentId}/signers/{signerId}` | The document without the signer. |
| `POST /api/v1/documents/{documentId}/signers/{signerId}/mark-signed` | The document; once every signer signed and the signed copy is attached, it is completed. |
| `POST /api/v1/documents/{documentId}/signers/{signerId}/mark-declined` | The declined document. |

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" -o nda.pdf \
  "https://app.example.com/api/v1/documents/$DOCUMENT_ID/files/pdf"
```

## Activities

Meetings, calls, notes and tasks, planned ahead or logged as done, on the records they are about. What the timings mean and who sees an activity is in [activities.md](activities.md). The reads need `activities:read`, the writes `activities:write`, and only the owners and members of the organisation may use them: a guest's token is never accepted. Bodies are JSON with snake case fields; times are ISO 8601, returned in UTC.

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/activities?linked_kind=contact&linked_id=...` | The timeline of a record: the planned activities first, the soonest on top, then the done ones, the latest first, each with its `type`, times, `outcome`, `links` (each with `available`) and `files`. The trash is left out. |
| `GET /api/v1/activities/planned` | Your own planned activities that are not done, from `from` (a day; today when left out) up to the day before `to` (30 days later when left out, at most 400). |
| `GET /api/v1/activities/{activityId}` | One activity, also one in the trash (`trashed: true`). |
| `GET /api/v1/activity-types` | The types in the order they were created; archived ones with `include_archived=true`. |
| `POST /api/v1/activities` | `201` with the activity: `type_id`, `subject`, `body`, and `planned_on` (a dated type) or `planned_at` with `duration_minutes` (a timed type), or `done: true` with `done_at` and `outcome`; `links`, a list of `{kind, id}`; `owner_user_id`. A time of the wrong kind for the type is `400 invalid_parameter` naming the field. |
| `PATCH /api/v1/activities/{activityId}` | The activity with what was given changed: `type_id`, `subject`, `body`, `planned_on`, `planned_at`, `duration_minutes`, `outcome`, `links` (replaces them), `owner_user_id`. |
| `POST /api/v1/activities/{activityId}/complete` | The activity done (`done_at`, `outcome`) with the next one it planned under `next`: `next_type_id`, `next_subject`, `next_planned_on` or `next_planned_at`, `next_duration_minutes`. Both or neither are saved. Done already is `409 already_done`. |
| `POST /api/v1/activities/{activityId}/trash` | The activity in the trash. |
| `POST /api/v1/activities/{activityId}/restore` | The activity back from the trash. |
| `POST /api/v1/activity-types` | `201` with the type: `name`, `key` (unique, never changes), `timing` (`timed`, `date`, `none`), `default_duration_minutes`. |
| `PATCH /api/v1/activity-types/{typeId}` | The type with its new `name`, `timing` or `default_duration_minutes`. |
| `POST /api/v1/activity-types/{typeId}/archive` | The archived type. |

```bash
curl -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Idempotency-Key: $(uuidgen)" \
  -H "Content-Type: application/json" -X POST \
  -d '{"outcome": "Agreed on an audit", "next_type_id": "'$TASK_TYPE_ID'", "next_subject": "Send the offer", "next_planned_on": "2026-12-01"}' \
  "https://app.example.com/api/v1/activities/$ACTIVITY_ID/complete"
```

## Received documents

The documents the firm received from suppliers (see [received documents](purchasing.md)). Reading needs `received_documents:read`, writing `received_documents:write`. An item has the firm, `state` (`draft` or `recorded`), `kind`, `treatment`, the evidence number, the supplier, the supplier's number, the variable symbol, the dates, `total` (negative for a credit note), `base`, `vat`, and the derived `paid`, `remaining` (both positive), `is_paid` and `last_paid_on`. The detail adds the supplier as the document names it, `vat_recap`, `lines`, the exchange rate, the deduction period, `payments`, `source` (`manual`, `upload` or `api`: how the document came in, which never changes) and `reading`. Each line has `key` (the id of a stored line, or `rate:21` for an implicit one; the key of a stored line stays the same when the line is edited, moved or another line is removed, and it is the `id` to send back with the line when you set the lines: a line with its `id` is kept, a line without one is new, and a line left out is removed; the same `id` twice is refused with `duplicate_line_id`), `text`, `base`, `base_czk` (null while the document has no exchange rate), `vat_rate` and `implicit`. A document without stored lines answers one implicit line per rate of its recap, its text the kind of the document, such as `Tax invoice`; one without a recap answers one line at rate `0` for its total, and none without a total. See [lines](purchasing.md#lines).

`reading` is `null` for a document never read, else `{state, requested_at, finished_at, sources, values, warnings, failure}`: `state` is `reading`, `read`, `nothing_found` or `failed`; `sources` the readers whose values it holds (`isdoc`, `payment_qr`, `factur_x`, `ai`); `values` has an entry per field found (`kind`, `treatment`, `supplier_name`, `supplier_number`, `variable_symbol`, `issue_date`, `tax_point_date`, `due_date`, `currency`, `total`, `vat_recap` and others) of `{value, source, applied}`, where `applied` false is a suggestion the draft did not take, and a supplier matched in the address book is `supplier_name`; `total` is in minor units of the currency read, dates are `YYYY-MM-DD`; `warnings` are codes such as `reading_partly_applied`; `failure` is a code (`reader_failed`, `original_unreadable` or `unexpected`) and never the text of a reader or a provider.

Query parameters of `GET /api/v1/received-documents`:

| Parameter | Value |
| --- | --- |
| `supplier_id` | The id of the supplier, a contact. |
| `state` | `draft` or `recorded`. |
| `unpaid` | `true` lists only recorded documents with something left to pay. Default `false`. |
| `due_before` | `YYYY-MM-DD`: only documents due before this day, not on it. |
| `trashed` | `true` lists only the documents in the trash. Default `false`. |
| `view` | The id of a saved view of the list `purchasing.received`, or the system key of one it ships, such as `purchasing.received.drafts`: only the documents it holds. Needs `views:read` too. |
| `filter` | Conditions as JSON in the format of [Saved views](#saved-views), such as `{"conditions": [{"field": "kind", "op": "is", "value": "credit_note"}]}`. A condition on `trashed` decides it instead of the `trashed` parameter. |
| `reading` | `reading`, `read`, `nothing_found` or `failed`: only documents whose file is in that state of reading. |
| `source` | `manual`, `upload` or `api`: only documents that came in that way. |
| `limit`, `cursor` | Paging, as above. |

The view `purchasing.received.to_review` lists the drafts whose file was read, found nothing or failed. The answer has `data`, `next_cursor` and `applied_view`, and refuses a view or filter as the invoices do; a view of another list is refused with `parameter` `view`.

The payables of a month are `?unpaid=true&due_before=` the first day of the next month.

The writes answer `{"data": {...}}` with the document as `GET /api/v1/received-documents/{documentId}` shows it, payments included:

| Method and path | Answer |
| --- | --- |
| `POST /api/v1/received-documents` | `201` with the new recorded document and `Location`. |
| `POST /api/v1/received-documents/upload` | `201` with the new draft and `Location`: its `source` is `api` and `reading.state` is `reading`, as the file is read in the background. Body: `file_name` and `content_base64`, see below. |
| `POST /api/v1/received-documents/{documentId}/trash` | The document in the trash. Body: `reason`. |
| `POST /api/v1/received-documents/{documentId}/payments` | `201` with the document and its new payment. |
| `POST /api/v1/received-documents/{documentId}/mark-paid` | The document paid in full by one payment of all that remains, dated today in the organisation's time zone. |
| `DELETE /api/v1/received-documents/{documentId}/payments/{paymentId}` | The document without the payment, for one recorded by mistake. |
| `PUT /api/v1/received-documents/{documentId}/lines` | The document with its new lines. Body: `lines`, all of them in their order; an empty list removes them. |
| `POST /api/v1/received-documents/{documentId}/read-again` | `202` with the draft and `reading.state` `reading`: its file is queued to be read again, by every reader, as on arrival. It never replaces a value a person typed: on a draft nobody changed since its last reading it fills the fields as on arrival, on a changed one only the fields that are empty; the rest stays a suggestion. A reading still `reading` after 10 minutes is stuck and may be read again. No body. |

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: 7c1d-record-fv-2026-123" \
  -d '{"kind": "tax_invoice", "supplier_id": "…", "supplier_number": "FV-2026-123", "issue_date": "2026-11-02", "due_date": "2026-11-16", "total": "1210.00", "vat_recap": ["21:1000.00:210.00"]}' \
  "https://app.example.com/api/v1/received-documents"
```

Recording takes the next evidence number of the firm, as the screen does, and needs no file; the original can be uploaded to the document in Foresi later. Fields of a record:

| Field | Value |
| --- | --- |
| `kind`, `supplier_id`, `issue_date`, `total` | Required. `kind` is `tax_invoice`, `simplified_receipt`, `foreign_invoice`, `credit_note`, `advance_request` or `other`. The supplier is a contact; find it by `registration_number` first, or create it with a contact write. |
| `treatment` | `domestic` (default), `domestic_reverse_charge`, `eu_service`, `non_eu_service`, `non_deductible`, or `not_vat_document` for a document without VAT. |
| `supplier_number`, `variable_symbol` | The supplier's number of the document and the symbol to pay with. |
| `tax_point_date`, `due_date`, `received_date` | `YYYY-MM-DD`. The tax point is the issue date when left out, the received date today. |
| `currency` | ISO 4217, default `CZK`. |
| `total` | What the document asks, a decimal such as `"1210.00"`; negative for a credit note. |
| `vat_recap` | One line per rate, `"rate:base:vat"`, such as `"21:1000.00:210.00"`; a document in another currency may add the VAT in CZK it prints as a fourth part. Needed for a tax invoice and a simplified receipt. |
| `exchange_rate` | CZK for one unit of the currency; the published rate of the tax point when left out. |
| `original_document_id` | The recorded document a credit note corrects. |
| `supply_code` | The code of the supply of a domestic reverse charge. |
| `lines` | Optional, at most 100: objects of `text` (at most 255 characters), `base` (a decimal in the document's currency, without VAT) and `vat_rate` (a rate of `vat_recap`, such as `"21"`). Per rate the bases add up to the base of the recap; a document without a recap adds them up to its total at rate `"0"`. |

Uploading takes one file per call: `file_name` (with its extension, at most 255 characters) and `content_base64` (the file, up to 10 MB decoded). A PDF, an image (PNG, JPEG, GIF, WebP, HEIC), an ISDOC file (`.isdoc`, or `.isdocx`, a ZIP told by its extension) or XML is accepted, judged by its bytes. The draft is read as one dropped on the screen is; poll `GET /api/v1/received-documents/{documentId}` until `reading.state` leaves `reading`; a reading still `reading` after 10 minutes is stuck, so call read again. The draft is completed and recorded in Foresi by a person: there is no operation to edit or record an existing draft, and `POST /api/v1/received-documents` makes a new document, so do not use it for an uploaded file (it would be a duplicate). What a reading finds never overwrites a change made after the upload. A file that is not valid base64, is empty, is larger than 10 MB, is of another type, or that the attachment store refuses is `400 invalid_parameter` with `parameter` `content_base64` and `reason` `file_invalid`, `file_too_large`, `file_type_unsupported`, or the store's `empty`, `too_big`, `failed` or `not_allowed`; no draft is made.

Fields of a payment: `paid_on` (`YYYY-MM-DD`, required), `amount` (a decimal in the document's currency, required, positive also for a credit note), `method` (`bank_transfer`, the default, `card`, `cash` or `other`) and `bank_movement_id` (optional). Mark as paid takes only `method` and `bank_movement_id`. A payment recorded by mistake is removed and recorded again; a document paid in full before stays so in the event feed.

What a received document write refuses:

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A field breaks a rule. `parameter` names it and `reason` says which, such as `date_invalid`, `amount_invalid`, `currency_unsupported`, `vat_recap_line_invalid`, `supplier_unknown`, `vat_recap_missing`, `exchange_rate_missing`, `variable_symbol` or `payment_amount`. The lines are refused with `parameter` `lines` and `lines_do_not_add_up`, `unknown_vat_rate`, `vat_rate`, `line_text_required`, `line_text_too_long`, `lines_too_many`, `duplicate_line_id` or `line_invalid` (a line that is no object of strings, or whose `id` is not a string). An `id` that is a string but no uuid, such as the key `rate:21` of an implicit line, is not refused: the line is new. |
| 404 | `not_found` | The organisation has no such received document. |
| 404 | `firm_unknown` | The instance has no firm yet: its first-run setup has not happened. |
| 409 | `overpaid` | The payment is more than what remains. |
| 409 | `paid` | The document is paid in full already. |
| 409 | `not_recorded` | The document is a draft; only a recorded one takes payments. |
| 409 | `trashed`, `locked` | The document is in the trash or in a closed month. |
| 409 | `recorded`, `no_original_file`, `reading_in_progress` | Read again: the document is recorded, the draft has no original file, or its reading was asked for less than 10 minutes ago and still runs. |
| 503 | `exchange_rate_unavailable` | No rate is published for the tax point yet; send `exchange_rate`. |

## Idempotency

Every write (`POST`, `PATCH` and `DELETE` under `/api/v1`) takes an optional `Idempotency-Key` header, so a request you retry after a timeout acts once. Use a new random value, such as a UUID, for each change, and the same value when you send that change again. A key is 1 to 255 visible ASCII characters.

- A repeat with the same key, method, path and body after the first finished answers the saved status and body, with `Idempotent-Replayed: true`.
- A repeat while the first is still running is refused with `409 idempotency_key_in_flight`. Wait a moment and send it again.
- The same key on the same endpoint with another path or body is refused with `422 idempotency_key_reused`.
- What is saved: a success, and a refusal of the change itself (`403 forbidden`, `404` and `409`). What is not saved, so you can fix the request and send the same key again: `400`, `401`, a missing scope, `429` and any `5xx`.
- A key belongs to its token and its endpoint, and is kept for 24 hours.

A request without a key is not protected: retrying a `POST` that timed out may create twice.

## Webhooks

A webhook endpoint is an HTTPS address of yours that receives the domain events it subscribes to, as soon as they happen, instead of you polling `/api/v1/events`. Foresi sends each event as a signed [Standard Webhooks](https://www.standardwebhooks.com/) request, retries a failing endpoint on a schedule, and records every delivery and every attempt.

An endpoint belongs to the organisation. The person who registers it is its owner. It can be seen and changed only by people who may read every module it subscribes to (today the owners and team members, for `invoicing`); anyone else neither lists it nor gets it, and is answered `403 forbidden`. If the owner later may no longer read a module, the endpoint is disabled before the next event of it is sent, and the owner is told by e-mail.

Reading endpoints and deliveries needs the `webhooks:read` scope. Everything else needs `webhooks:write`, including reading the secret. Bodies are JSON (`Content-Type: application/json`) with snake case fields. Send an [`Idempotency-Key`](#idempotency) with a `POST`, so a retry after a timeout does not register a second endpoint. A saved answer holds the secret of the endpoint for the 24 hours its key is kept.

| Method and path | Scope | Answer |
| --- | --- | --- |
| `GET /api/v1/webhook-endpoints` | `webhooks:read` | The endpoints, newest first, paged. |
| `POST /api/v1/webhook-endpoints` | `webhooks:write` | `201` with the endpoint and its secret. A ping is queued. |
| `GET /api/v1/webhook-endpoints/{id}` | `webhooks:read` | The endpoint, without its secret. |
| `PATCH /api/v1/webhook-endpoints/{id}` | `webhooks:write` | The endpoint as it is now. |
| `DELETE /api/v1/webhook-endpoints/{id}` | `webhooks:write` | `204`. Its deliveries and their attempts are deleted with it. |
| `POST /api/v1/webhook-endpoints/{id}/secret/rotate` | `webhooks:write` | `200` with the new secret. |
| `GET /api/v1/webhook-endpoints/{id}/secret` | `webhooks:write` | The secret it is signed with now. |
| `POST /api/v1/webhook-endpoints/{id}/ping` | `webhooks:write` | `202`. A ping is queued. |
| `GET /api/v1/webhook-endpoints/{id}/deliveries` | `webhooks:read` | Its deliveries of the last 30 days, newest first, paged. |
| `GET /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}` | `webhooks:read` | One delivery with its attempts. |
| `POST /api/v1/webhook-endpoints/{id}/deliveries/{deliveryId}/redeliver` | `webhooks:write` | `202`. One more attempt is queued. |

### Register an endpoint

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"url": "https://hooks.example.com/foresi", "description": "Accounting sync", "event_types": ["invoicing.invoice.paid", "invoicing.payment.*"]}' \
  "https://app.example.com/api/v1/webhook-endpoints"
```

| Field | Value |
| --- | --- |
| `url` | Required. `https`, with a host, without a user name or password, at most 2048 characters. The host must resolve, and only to public addresses: private, loopback and link-local networks are refused. |
| `event_types` | Required. Event names or `module.*`, such as `invoicing.*`. A bare `*` is refused, so an endpoint never receives the events of modules added later by surprise. An unknown type is `400 invalid_parameter`; a type of a module you may not read is `403 forbidden`. |
| `description` | Optional text of up to 255 characters. |
| `version` | Optional, 1 by default. The event version the endpoint receives: an event of another version is not sent to it. |

The answer is `201 Created` with a `Location` header and the endpoint:

```json
{
  "data": {
    "id": "0192f0c4-...",
    "url": "https://hooks.example.com/foresi",
    "description": "Accounting sync",
    "event_types": ["invoicing.invoice.paid", "invoicing.payment.*"],
    "version": 1,
    "active": true,
    "disabled_reason": null,
    "owner_id": "0192e9a1-...",
    "previous_secret_expires_at": null,
    "created_at": "2026-11-13T10:00:00+00:00",
    "updated_at": "2026-11-13T10:00:00+00:00",
    "secret": "whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw"
  }
}
```

Store the `secret` in your receiver's secret store. The endpoint is shown everywhere else without it; you can read it again with `GET .../secret`, which needs the `write` scope. Foresi keeps it sealed with a key of the installation (`WEBHOOK_SECRET_KEYS`); while the operator has not set one, creating an endpoint, rotating and reading its secret, `POST .../ping` and `POST .../redeliver` answer `503 service_unavailable`. Listing, showing, changing (`PATCH`) and deleting endpoints work without it.

Right after registering, Foresi sends a **ping** to the endpoint, so you can see at once whether your receiver verifies it. `POST .../ping` sends another. A ping is one attempt with no retries, and it shows in the deliveries with `kind` `ping`. Its body has the shape of an event:

```json
{"id": "0192f0c4-...", "type": "webhooks.endpoint.ping", "version": 1, "occurred_at": "2026-11-13T10:00:00+00:00", "subject": {"type": "webhook_endpoint", "id": "<endpoint id>", "url": "/api/v1/webhook-endpoints/<endpoint id>"}, "data": {"endpoint_id": "<endpoint id>"}, "correlation_id": null, "actor": {"kind": "api_token"}}
```

`ping` and `redeliver` answer `202` with `{"data": {"delivery_id": "...", "attempt_id": "..."}}`. The attempt is made by a worker shortly after; read its outcome in the delivery. A disabled endpoint is not pinged: `409 conflict`.

### Change, disable and delete

`PATCH /api/v1/webhook-endpoints/{id}` changes only the fields you send: `url`, `event_types`, `description` (`null` clears it), `version` and `active`. The same rules apply as when registering.

```bash
curl -X PATCH -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"active": false}' "https://app.example.com/api/v1/webhook-endpoints/0192f0c4-..."
```

`"active": false` disables the endpoint (`disabled_reason` `manual`); `"active": true` enables it again and clears the reason. Foresi disables an endpoint on its own too, and e-mails the owner:

| `disabled_reason` | When |
| --- | --- |
| `gone` | The endpoint answered `410 Gone`. |
| `failing` | Every scheduled attempt has failed for 5 days. |
| `owner_lost_access` | The owner may no longer read a module the endpoint subscribes to. |
| `manual` | A person disabled it. |

The pending deliveries of a disabled endpoint end as `failed`. Enabling it starts afresh; events that happened while it was disabled are not sent, but you can read them in the [events feed](#events).

`DELETE /api/v1/webhook-endpoints/{id}` answers `204 No Content` and deletes the endpoint with all its deliveries and their attempts.

### Rotate the secret

```bash
curl -X POST -H "Authorization: Bearer $FORESI_API_TOKEN" -H "Content-Type: application/json" \
  -d '{"keep_previous_hours": 24}' "https://app.example.com/api/v1/webhook-endpoints/0192f0c4-.../secret/rotate"
```

The answer is `{"data": {"secret": "whsec_...", "previous_secret_expires_at": "..."}}`. From now on every request is signed with the new secret **and** the previous one, until `previous_secret_expires_at`, so you can deploy the new secret to your receiver without losing a request. `keep_previous_hours` is 0 to 168 (seven days), 24 without a body; 0 drops the previous secret at once, for a secret that leaked.

### Deliveries

A delivery is one event (or one ping) for one endpoint. `GET .../deliveries` lists them newest first, paged like the other lists, with `id`, `endpoint_id`, `event_id`, `event_type`, `kind` (`event` or `ping`), `status` (`pending`, `succeeded` or `failed`), `attempt_count`, `last_status_code`, `next_attempt_at`, `created_at` and `finished_at`. `GET .../deliveries/{deliveryId}` adds `attempts`, oldest first, each with `id`, `attempted_at`, `duration_ms`, `status_code`, `error` (`timeout`, `tls`, `blocked_address`, `connection`, `redirect`, `endpoint_disabled` or `internal`, when there was no answer to record; `internal` means the endpoint's secret could not be opened, and the attempt counts as a failure) and `trigger` (`schedule`, `manual` or `ping`). The body sent and anything your receiver answered are never stored. Deliveries are kept 30 days.

An attempt succeeds only with a `2xx` answer within 15 seconds. Redirects are not followed and count as failures. A failed event is attempted again after 5 seconds, 5 minutes, 30 minutes, 2, 5, 10, 14, 20 and 24 hours, 10 attempts over about three days, and is then `failed`.

`POST .../deliveries/{deliveryId}/redeliver` sends a `succeeded` or `failed` delivery once more, with the same `webhook-id` and a new timestamp and signature. A delivery that is still `pending`, a ping, or a delivery of a disabled endpoint is answered `409 conflict`.

### What your receiver gets

Each request is a `POST` with `Content-Type: application/json`, the event as body, and three headers:

| Header | Value |
| --- | --- |
| `webhook-id` | The event's `id`, the same on every attempt and redelivery. Use it to drop an event you have handled already: delivery is at least once. |
| `webhook-timestamp` | The time of this attempt, in Unix seconds. |
| `webhook-signature` | `v1,<base64 signature>`; during a rotation two of them, separated by a space. |

The body is the event as the [events feed](#events) shows it, without `position`: `id`, `type`, `version`, `occurred_at`, `subject`, `data`, `correlation_id` and `actor`. Answer `2xx` quickly and do the work afterwards; answering `410 Gone` disables the endpoint at once.

### Verify the signature

Verify every request before you trust it, over the **raw body** as received: parsing and re-encoding the JSON changes the bytes. The [Standard Webhooks libraries](https://github.com/standard-webhooks/standard-webhooks/tree/main/libraries) do it in one call, also checking that the timestamp is recent (5 minutes either way), which stops a recorded request from being replayed. They take the secret with its `whsec_` prefix.

Python (`pip install standardwebhooks`):

```python
import os
from standardwebhooks.webhooks import Webhook

wh = Webhook(os.environ["FORESI_WEBHOOK_SECRET"])
event = wh.verify(request.get_data(), dict(request.headers))  # raises when the signature or timestamp is wrong
```

JavaScript (`npm install standardwebhooks`):

```js
import { Webhook } from "standardwebhooks";

const wh = new Webhook(process.env.FORESI_WEBHOOK_SECRET);
const event = wh.verify(rawBody, req.headers); // throws when the signature or timestamp is wrong
```

Without a library, the signature is HMAC-SHA256 over `{webhook-id}.{webhook-timestamp}.{body}`, keyed with the base64-decoded part of the secret after `whsec_`, base64-encoded. In PHP:

```php
$key = base64_decode(substr(getenv('FORESI_WEBHOOK_SECRET'), strlen('whsec_')), true);
$id = $_SERVER['HTTP_WEBHOOK_ID'];
$timestamp = (int) $_SERVER['HTTP_WEBHOOK_TIMESTAMP'];
$body = file_get_contents('php://input');

$expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));
$valid = false;
if (abs(time() - $timestamp) <= 300) {
    foreach (explode(' ', $_SERVER['HTTP_WEBHOOK_SIGNATURE']) as $signature) {
        $valid = $valid || (str_starts_with($signature, 'v1,') && hash_equals($expected, substr($signature, 3)));
    }
}
```

Accept the request when any one of the signatures matches, and compare in constant time (`hash_equals`).

## Errors

An error is an [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem document, `application/problem+json`:

```json
{
  "type": "about:blank",
  "title": "Bad Request",
  "status": 400,
  "detail": "The cursor is not one this API gave.",
  "code": "invalid_parameter",
  "parameter": "cursor"
}
```

Program on `code`, which is stable. `detail` is a sentence for people and may change.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `invalid_parameter` | A query parameter or a field of the body cannot be used. `parameter` names it. |
| 400 | `bad_request` | The request is malformed in another way. |
| 401 | `missing_token` | No bearer token was sent. |
| 401 | `invalid_token` | The token is unknown, or its person no longer works for the organisation. |
| 401 | `token_expired` | The token has expired. Issue a new one. |
| 401 | `token_revoked` | The token has been revoked. |
| 403 | `insufficient_scope` | The token lacks the scope the request needs. `WWW-Authenticate` names it. |
| 403 | `forbidden` | The person is not allowed to read or change this. |
| 403 | `confirmation_required` | The change needs a person to confirm it in Foresi, which the API cannot do yet. No endpoint needs it today. |
| 404 | `not_found` | The organisation has no such record, or the path does not exist. |
| 405 | `method_not_allowed` | The endpoint does not take this method. `Allow` lists the ones it does. |
| 406 | `not_acceptable` | The `Accept` header asks for something the API does not send. |
| 409 | `conflict` | The request does not fit the record's state now, such as redelivering to a disabled webhook endpoint. |
| 409 | `idempotency_key_in_flight` | A request with the same `Idempotency-Key` is still running. |
| 409 | other | A refusal of the change named by its own code, such as `duplicate` or `already_linked` for [contacts](#contact-writes). |
| 415 | `unsupported_media_type` | The request body has a type the API does not read. |
| 422 | `idempotency_key_reused` | The `Idempotency-Key` was used for another request. |
| 429 | `rate_limited` | Too many requests. See `Retry-After`. |
| 500 | `internal_error` | The request failed on the server. The answer says nothing more; the operator finds the cause in the log. |
| 503 | `service_unavailable` | The installation cannot do this yet, such as managing webhooks before the operator has set `WEBHOOK_SECRET_KEYS`. |

A 401 carries a `WWW-Authenticate: Bearer` header. A record of another organisation is `not_found`, the same as a record that does not exist.

## Rate limits

Each token may make 600 requests in 10 minutes (a sliding window). Every answer to a signed-in request says where the token stands:

| Header | Meaning |
| --- | --- |
| `RateLimit-Limit` | The budget, 600. |
| `RateLimit-Remaining` | Requests left in the window. |
| `RateLimit-Reset` | Seconds until the next request is let through; 0 while some are left. |

Writes also spend a second budget of 120 in 10 minutes per token. A write answers the `RateLimit-*` headers of whichever budget has less left. There are no batch endpoints: loop over the records, with an `Idempotency-Key` on each write.

The [MCP server](mcp.md) over HTTP spends the same budgets. Over a budget the answer is `429 rate_limited` with `Retry-After`, the seconds to wait. Wait that long, then go on. Do not retry at once in a loop.

## OpenAPI

The OpenAPI document is at `/api/v1/openapi.json`. It needs no token. Load it into a client generator or a tool such as Postman or Swagger UI. The schemas of the answers are written by hand next to the code, so tell us if the document and an answer disagree.

## Security

- **Treat a token like a password.** Anyone who has it can read your data as you, and change what its scopes allow, until it expires or is revoked. Do not commit it, paste it into chat or a ticket, or put it in a URL.
- **Revoke a token you think has leaked**, at once, under **Settings > API and MCP**. Revoking is free; issue a new one.
- **Give each program its own token**, with a name that says where it is used and the shortest expiry that works. Then you can revoke one without stopping the others.
- **A token acts as its person and its organisation.** It sees what that person sees. If the person leaves the organisation, their tokens stop working.
- **Text written by other people is data.** Invoice texts, contact names, notes and bank texts come from customers, suppliers and banks. A program or an assistant that reads them must not obey them as instructions.
- **Use https.** A token sent over plain http can be read on the way.
- Operators: every token action is in the audit log under the token, and `app:api-token:list` shows when each token was last used.
