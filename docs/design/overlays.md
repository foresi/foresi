# Overlays: where a record opens

A record picked from a list opens in place, over the list, never as a jump to another page.

## The rule

1. **Size decides the layer.** A medium or large record (a contact, a task, an invoice, a purchase
   document, a webhook, a recurring invoice) opens in the side sheet, `Ui:Sheet` md (640 px,
   resizable). A small record (a label, a number series, a person, a bank account) opens in a modal
   `Ui:Dialog` md. Places you work in, such as the project board and the import wizard, stay pages:
   they are navigation, not records.
2. **The header of the sheet** is one row, as a page header is: the title at the page-title size,
   cut with an ellipsis (the full text in its `title`), then the same actions as the record's page
   header in the small size, a divider, a Full screen toggle (the width of the content panel,
   remembered per browser), Open as page (the record's URL) and Close. On a phone the actions take a
   row of their own under the title. A record sizes its action buttons with `record_action_size`.
3. **Edit happens in place.** Edit loads the form into the same surface (sheet, dialog or page);
   Save and Cancel go back to the detail there. A document with lines (an invoice, a recurring
   invoice, a purchase document) widens its sheet to full screen while it is edited.
4. **The URL is the record's.** While a record is open its URL is in the address bar; Back closes
   it and gives the list its URL back. A reload, a new tab, a bookmark or a browser without
   JavaScript gets the record's page at the same URL.
5. **On a phone** the sheet fills the screen and Back closes it.
6. **Nesting.** A dialog may open over a sheet (a confirmation, a small form); a sheet never opens
   over a dialog; two levels at most.
7. Moving between records with the keyboard (j/k) is not part of the rule.

## How a screen follows it

- A list row says where its record opens: `opens: 'sheet'`, `'dialog'` or `'page'` (the default)
  on a `Ui:DataTable` row. The row's link gets `data-sheet` or `data-dialog`, and the hosts in
  `layout/_dialogs.html.twig` load it.
- The host asks with the header `X-Foresi-Dialog`: `sheet` for the side sheet, `1` for a dialog.
  `overlay()` in Twig answers `sheet`, `dialog` or `null` (the page); `in_dialog()` is true for both.
- The detail and its edit form extend `layout/record.html.twig` once and fill the blocks of the page
  (`title`, `actions`, `content`). Set `record_overlay` to `dialog` for a small record and
  `record_fields` to true for a form. Set `record_full` to true for a form with lines: the sheet
  fills the content panel while it shows and keeps the width it remembers for the next record; the
  Full screen toggle or the grip ends it. Asked by its own layer, the template is the overlay;
  otherwise it is the page.
- Inside a record, a link in a `data-in-place` area (Edit, Cancel, the tabs) and the answer of a form
  load in the same host. A form that saves answers with a redirect, as on the page; the host loads its
  target in place and loads the list under it again once it closes.
- A record whose title changes where it is read (a task) gives `record_title_edit`, the `titleEdit`
  of its page header: the sheet's header shows the same inline field, and the focus starts on the title.
- The same record loaded again after a change (a property saved, a comment added) keeps where its body
  was scrolled and the focus on the field that changed. A dialog that finishes over a record in the
  sheet (log time, a new subtask, sharing) shows what comes next in the sheet instead of a page.

## Sources

- Shopify Polaris, Modal: a last resort, not for actions on the page; no modal launched from
  another — https://shopify.dev/docs/api/app-home/web-components/overlays/modal
- Shopify Polaris, Page: one primary action and up to three secondary ones in the header —
  https://shopify.dev/docs/api/app-home/web-components/layout-and-structure/page
- GitHub Primer PageHeader: the title, then the trailing actions on the same row; on narrow
  viewports they become items of an overflow button —
  https://primer.style/product/components/page-header/guidelines/
- Atlassian Drawer: a secondary task while staying on the page; focus to the first item on open,
  back to the trigger on close, Escape dismisses — https://atlassian.design/components/drawer/usage
- Atlassian Modal dialog: not for complex interactions or large tables; dialogs never trigger other
  dialogs — https://atlassian.design/components/modal-dialog/usage
- GitHub Primer Dialog: a right side sheet as a dialog position; up to two nested dialogs, Escape
  closes the top one; full screen on narrow viewports —
  https://primer.style/product/components/dialog/guidelines/
- Material Components, Side sheet: a modal side sheet has a scrim and handles Back —
  https://github.com/material-components/material-components-android/blob/master/docs/components/SideSheet.md
- W3C APG, Dialog (Modal): focus moves in, stays in, Escape closes, focus returns to the opener —
  https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/
- W3C APG, Button: a toggle button carries `aria-pressed` —
  https://www.w3.org/WAI/ARIA/apg/patterns/button/
- Turbo Frames: a frame can advance the history; the application renders the target on a reload —
  https://turbo.hotwired.dev/handbook/frames
- NN/g, Modal and nonmodal dialogs: avoid modal dialogs for complex decisions —
  https://www.nngroup.com/articles/modal-nonmodal-dialog/
