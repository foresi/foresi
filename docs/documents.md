# Documents

The Documents module (`src/Documents`, id `documents`, scope `documents`) makes contracts and other documents from templates: an NDA filled with the data of the firm and of a client, frozen as a PDF, signed by the people it names, and kept with its signed copy beside the record it belongs to.

The module is optional. It requires Attachment, Audit, Identity, Instance, Ui and the shared core. Other modules plug into it through `Foresi\Documents\Contract`: they offer **variables** for templates (`DocumentVariableSource`, such as `firm.name` or `client.name`) and **records** a document can link to (`DocumentLinkTarget`, such as a contact). The records of a kind no installed module offers cannot be linked.

## Staff only

Documents are for the owners and members of the organisation. A guest never sees the screens, the API or the MCP tools, whatever scopes their connection asks for ([ADR 0041](adr/0041-guests-over-mcp.md)).

## Types and templates

A **document type** is a kind of document, such as `nda`: a name, a unique code that never changes, whether its documents are signed, and the level of electronic signature (`simple`, `advanced` or `qualified`). The migration seeds the type `nda`. A type is archived, never deleted.

A **template** belongs to a type and has **versions**. A version holds the HTML body and the paper: A4 or Letter, portrait or landscape, and four margins in whole millimetres, 0 to 60. A version is edited in place until a document is generated from it. Then it is **frozen**, and saving the template makes version n+1; the documents made from the old version keep it.

### The template subset

The body is HTML in the subset that the PDF renderer draws well: headings, paragraphs, lists, tables, a few inline tags and a short list of CSS properties in `mm`, `pt` and `%`. The full list, with what is not available (images, links, floats, flexbox, italic Inter), is in [ADR 0042](adr/0042-document-pdf-renderer.md#the-template-subset). The footer "page X of Y" is drawn by the module.

Placeholders are Twig in a sandbox, such as `{{ client.name }}`. Allowed are:

- the tags `if`, `for` and `set`;
- the filters `escape`, `upper`, `lower`, `date`, `number_format`, `nl2br`, `default`, `join` and `length`;
- no functions, no methods and no properties of objects.

Values are HTML escaped. A body that uses anything else is refused when it is saved, with the construct in `details.construct` (`template_not_allowed`). The variables a body can use, with a label and an example each, are in the template detail (`variables`), and the ones a version uses are in `version.uses`.

### Variables from the address book

The address book (Party) offers two sources when Documents is installed:

| Variable | Value |
| --- | --- |
| `firm.name`, `firm.registration_number`, `firm.vat_id` | The firm of the instance, as its settings hold it when the document is generated. |
| `firm.address_lines` | The firm's address as rows: `{% for item in firm.address_lines %}{{ item.line }}<br>{% endfor %}` (street, postal code with city, country). |
| `firm.bank_account` | The IBAN of the primary bank account, in groups of four. |
| `client.name`, `client.registration_number`, `client.vat_id`, `client.address_lines` | The contact the document links to (the first `contact` link): its billing address, or else its first address. |
| `client.persons` | The contact persons of a company in the order they were linked, as rows with `name`, `position`, `email` and `phone`. |

A value that is not known is empty, such as the VAT ID of a firm that is not a VAT payer. The client variables need a link to a contact that is in the address book: without one, or once the contact is archived, a template that uses them is refused when it is generated. The address book keeps no e-mail or phone of the firm, so there are no such variables.

A contact shows its documents on the **Documents** tab, with **New document** that opens the dialog with the contact linked.

Another module opens the dialog at `/documents/new` with `link_kind` and `link_id` for its record, and with `links[]=kind:id`, repeated, for further records, such as a deal and its client. Each link is shown in the dialog and the document is created with all of them; a malformed or repeated link is left out.

## Documents and their statuses

A document has a type, a template, a title and links to the records it belongs to. It starts as a **draft**.

| Status | Meaning | How it gets there |
| --- | --- | --- |
| `draft` | Being prepared; the title, links and signers can change. | Created, or made again from a final document. |
| `ready` | Generated: the values, the PDF and the template version are frozen. | Generate, from draft. |
| `out_for_signature` | Sent to the signers. | Mark sent, from ready; or the first signature or signed copy recorded on a ready document. |
| `completed` | Every signer signed and the signed copy is attached. | Checked after each of those writes, in either order. |
| `declined` | A signer declined. | Mark a signer declined. |
| `cancelled` | No longer wanted; its history stays. | Cancel, from any status that is not final. |
| `expired` | Reserved; nothing sets it yet. | |

`completed`, `declined`, `cancelled` and `expired` are final. **Generate again** makes a new draft from a final document with the same type, links and signers and the latest version of the template.

Generating resolves the variables from the linked records, renders the PDF and stores it as an attachment of the document. A template that uses a variable with no value for the document (`deal.title` when no deal is linked) is refused with `template_variables_missing` and the names in `details.missing`. A link whose record was trashed or archived later stays on the document and shows as unavailable.

The files of a document that has left draft are never deleted. Each stored file keeps its SHA-256.

## Manual signing

Signers have a name, an optional e-mail and contact person, a side (`ours` for the firm, `theirs` for the other party) and a signing order from 1. Signers of one order sign in parallel; the order is shown, not enforced. Signers can be added, changed and removed until the document is completed. A document of a type that is not signed takes no signers (`signing_not_needed`).

Signing happens outside Foresi, on paper or in any signing tool. Then you:

1. upload the signed copy (a PDF up to 10 MB), allowed from ready or out for signature;
2. mark each signer signed, or the one who refused declined.

Once every signer is signed and the signed copy is attached, the document is completed.

### SignatureProvider: designed, not built

`Foresi\Documents\Contract\SignatureProvider` declares how an electronic signature service would plug in: `code()`, `send(DocumentForSigning): string` (the envelope id) and `fetchFinal(string $envelopeId): SignedFiles` (the signed PDF and the certificate). Nothing implements it yet. The document already keeps `provider`, `envelope_id` and a `certificate` file for it; they stay empty with manual signing.

## API

The reads need `documents:read`, the writes `documents:write`. Bodies are JSON with snake case fields. See [the REST API](api.md#documents) for the endpoints.

| Method and path | Answer |
| --- | --- |
| `GET /api/v1/document-types` | The types by name; `include_archived=true` lists the archived ones too. |
| `GET /api/v1/document-templates` | The templates by name with their latest version, without the body; filter with `type_id`, `include_archived=true`. |
| `GET /api/v1/document-templates/{templateId}` | One template: the latest version with its body, paper and `uses`, the versions, and the `variables` a body can use. |
| `GET /api/v1/documents` | The documents, newest first. Filters: `type_id`, `status`, `linked_kind` with `linked_id`; paging with `limit` (1 to 200, 50 by default) and `offset`. |
| `GET /api/v1/documents/{documentId}` | One document with its links, signers in signing order, files and frozen values. |
| `GET /api/v1/documents/{documentId}/files/{file}` | The bytes of the stored file: `pdf`, `signed` or `certificate`. `404` until it exists. |
| `POST /api/v1/document-types`, `PATCH .../{typeId}`, `POST .../{typeId}/archive` | Create, change and archive a type. |
| `POST /api/v1/document-templates`, `POST .../{templateId}/versions`, `POST .../{templateId}/archive` | Create a template with its first version, save a version (in place or the next number), archive. |
| `POST /api/v1/documents`, `PATCH .../{documentId}` | Create a draft with its `links` (`[{kind, id}]`), change a draft. |
| `POST .../{documentId}/generate`, `/mark-sent`, `/signed-copy`, `/cancel`, `/generate-again` | Move the document on. The signed copy is `file_name` and `content_base64`. |
| `POST .../{documentId}/signers`, `PATCH` and `DELETE .../signers/{signerId}`, `POST .../signers/{signerId}/mark-signed`, `/mark-declined` | Manage the signers and record their answer. |

Every write takes an `Idempotency-Key`; a retry with the same key answers the same and acts once.

## MCP tools

The read tools are `foresi_list_documents`, `foresi_get_document`, `foresi_list_document_types`, `foresi_list_document_templates` and `foresi_get_document_template`. The write tools are `foresi_create_document_type`, `foresi_update_document_type`, `foresi_archive_document_type`, `foresi_create_document_template`, `foresi_save_document_template_version`, `foresi_archive_document_template`, `foresi_create_document`, `foresi_update_document`, `foresi_generate_document`, `foresi_mark_document_sent`, `foresi_attach_signed_document_copy`, `foresi_cancel_document`, `foresi_generate_document_again`, `foresi_add_document_signer`, `foresi_update_document_signer`, `foresi_remove_document_signer`, `foresi_mark_document_signer_signed` and `foresi_mark_document_signer_declined`. They take the fields of the REST API and answer what it answers; see [the MCP server](mcp.md#tools).

## Events

`documents.document.created`, `.generated`, `.sent_for_signature`, `.completed`, `.declined` and `.cancelled`, and `documents.signer.signed`. Their payloads hold ids, the type code and the status, never names or e-mails. The owners and members read them.
