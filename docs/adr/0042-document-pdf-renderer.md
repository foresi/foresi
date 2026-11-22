# 42. Document PDFs are rendered by tc-lib-pdf from an HTML subset

* Status: accepted
* Date: 2026-11-22

## Context and problem statement

The Documents module generates contracts (an NDA, a service contract) from templates that people write in HTML with placeholders. Each generated document is frozen as a PDF that is then signed and archived. Invoicing already renders its PDFs with `tecnickcom/tc-lib-pdf` (ADR 0012): PDF/A-3a, the bundled Inter fonts, local files only and no network. A contract looks different from an invoice: a title, numbered clauses with nested points, a table of the parties, a two-column signature block and a footer "page X of Y" on every page, on A4 with 20 mm margins. Before Documents is built, we had to know whether tc-lib-pdf renders such a template well enough, or whether a browser engine (headless Chromium) is needed.

## Decision drivers

* One PDF stack in the core: the same fonts, the same PDF/A setup, nothing that drifts between two copies.
* No new server dependency. Headless Chromium would be a large binary on every instance, a sandbox to keep and timeouts to tune.
* The output must read as a decent contract: clause numbering, the signature block side by side, no clause heading left alone at the bottom of a page.
* A template author needs a written list of what works, not trial and error.

## The spike

A contract-style template (two parties, six articles, nested points, Czech diacritics, a signature table) was rendered with tc-lib-pdf through the options of the Invoicing engine, PDF/A-3a, fonts from the bundled directory only, `allowedHosts` empty, with 20 mm margins and a footer drawn as a pagination artifact on every page. It was compared on screen with a real NDA printed from Chromium.

Measured result:

* Two A4 pages in 0.15 s; four pages (one article stretched over two pages) in 0.20 s.
* Inter Regular, Inter SemiBold and Inter Display embedded as subsets; all Czech diacritics and typographic quotes render.
* The party table, the grey labels and the two-column signature block with lines above the names render as in the browser.
* An article wrapped in `<div style="page-break-inside: avoid">` moves whole to the next page, so its heading never stays alone. An article longer than a page still breaks normally, between its clauses.
* What does not work: `page-break-after: avoid` on a heading is ignored; numbering like "1.1" cannot come from nested `<ol>` (a nested list numbers "a.", "b." or "1.", "2."); a class selector does not always reach a cell of a table nested in another table, so a cell that needs its own font size uses a `style` attribute.

The result passes the bar. Headless Chromium is not needed.

## Decision outcome

### Engine

Documents renders with tc-lib-pdf. Documents depends only on its own `Documents\Application\PdfRenderer` interface (`render(string $html, PageSettings $page, string $title, string $language): string`), so the engine can change without touching the module.

### Where the engine lives

`PdfEngine`, `EmbeddedFile`, `QrFigure` and the Inter fonts move from Invoicing to `Shared/Infrastructure/Pdf/` and `src/Shared/resources/pdf-fonts`. Invoicing and Documents use the same engine; Invoicing's output is unchanged. The engine gains an optional page layout (size, orientation, four margins) whose default is the invoice layout, so an invoice is drawn exactly as before. Identity in the core already pulls `tc-lib-barcode`, and a second copy of the font and PDF/A setup would drift.

### The template subset

What a document template may use. Anything else is either ignored or drawn differently from a browser.

* **Page:** A4 or Letter, portrait or landscape, four margins in millimetres, set on the template version, not in CSS. The footer "page X of Y" is drawn by the module, not by the template.
* **Block tags:** `h1`–`h4`, `p`, `div`, `br`, `hr`, `ol`, `ul`, `li` (nested lists one level deep), `table`, `tr`, `td`, `th` with `width`, `colspan`, `cellpadding`.
* **Inline tags:** `b`, `strong`, `i`, `em`, `u`, `span`, `small`, `sup`, `sub`.
* **CSS** in one `<style>` block or `style` attributes: `font-family` (`intertnum` for text, `interdisplay` for headings), `font-size` and `font-weight` (normal or bold), `color`, `background-color`, `text-align`, `vertical-align`, `line-height`, `margin` and `padding`, `border`, `border-top`, `border-bottom` (solid), `width` and `height` on cells, `page-break-before: always`, `page-break-inside: avoid`. Selectors: tag, `.class`, `tag.class` and descendants.
* **Units:** `mm` and `pt` for lengths, `%` for table widths, plain numbers for `line-height`.
* **Patterns:** an article is `<div style="page-break-inside: avoid">` around its heading and clauses. Numbered clauses ("1.1", "1.2") are a two-column table, the number in a 10 mm cell. The signature block is a table of two 45 % columns.
* **Not available:** images, links, forms, scripts, external fonts or stylesheets, `float`, `position`, flexbox and grid, CSS counters, italic Inter (no italic face is bundled; `i` and `em` keep the regular face).

### Consequences

* Good: one PDF stack, no new binary on the server, about 0.1 s per page.
* Good: Invoicing's PDF tests run against the moved engine with only their imports changed.
* Bad: template authors write to a subset, not to full HTML and CSS; the template editor links this ADR's list in `docs/documents.md`.
* Bad: if a future template needs what the subset lacks (floats, images), we revisit this decision; the `PdfRenderer` interface keeps that change inside Documents.
