# List pages: one set of controls

Every page that lists records (invoices, purchase documents, contacts, bank movements, recurring
invoices, tasks, projects, notifications, time, the send log, and every list added later) uses the
same controls in the same places. A person who learns one list knows them all.

## The rule

1. **Saved views are the tabs.** The tabs above a list are saved views (`ListDefinition` defaults
   synced by `app:views:sync`, plus the views people save), never hard-coded links. At most six
   views are primary tabs. The rest sit under "More views" (`saved_view.more_views`). A module
   declares its list in an `Application/View/<X>Lists` class that implements `ListDefinitionSource`.
2. **One view bar.** `Ui:Tabs` receives `:more` and the `_view_menu` actions block. Next to it,
   `_view_actions` shows Save, Save as new and Reset, and `_view_dialogs` follows the list. Changing
   filters, sort or display puts a dot on the tab until the change is saved or reset.
3. **Filters through "+ Přidat filtr".** `Ui:FilterBar` gets `:conditions` and `:builder`. Search is
   always there. A filter is a field of the list's definition, added in the builder. No fixed
   select sits above or beside the builder. A pinned quick filter is allowed only where the spec
   names one (the month on documents, which offers relative dates).
4. **Columns sheet.** Every list has a `<X>Columns` registry and `Ui:ColumnPicker`. The default
   columns are what the list showed before. The display a person last used on a view is remembered
   (`RememberedDisplay`).
5. **The URL holds the working state.** It holds `view=`, `f=`, `q=`, `sort=` and `cols=`. A link
   can be shared and survives a reload. Old URLs keep working: a default view's slug equals the old
   `tab=` value, and an old parameter (`month=`, `client=`, `status=` …) becomes a condition in
   `f=`.
6. **Counts only where they help.** A tab shows a count only where the list counted before, or where
   a person turns it on. Counts are summed in one query with the page.
7. **What is not a list.** A board, the time week, a dashboard, a settings hub (firm, people, API
   tokens) and an import preview keep plain `Ui:Tabs` as navigation. They are places, not lists of
   records (see [overlays](overlays.md)).

## Why

Martin asked for one behaviour on every list. The pattern follows products that people already
know:
- Linear and GitHub Projects keep views as tabs and applied filters in the URL. GitHub Projects
  marks an unsaved change with a dot.
- Attio, level.io and Shopify offer Save, Save as new and Reset.
- Attio, HubSpot and ABRA Flexi let any user pick the columns.

The decisions are D1–D14 and D-display in the spec "Quick filters and saved views".

## Checklist for a new list

- [ ] `<X>Lists` (`ListDefinitionSource`) with fields and default views; `app:views:sync` lists it.
- [ ] Doctrine compiles conditions through `Shared\Infrastructure\View\DqlConditions`.
- [ ] The controller uses `ViewBar::of`, `FilterSheet::of`, `ViewState::sortParam` and
      `RememberedDisplay`, and redirects `FilterRows::MARKER` to `f=`.
- [ ] The template has the view bar, the FilterBar with builder, the ColumnPicker and the dialogs, as
      `src/Invoicing/templates/index.html.twig` does.
- [ ] Tests cover the old URLs, the builder redirect, the dot, Save as new and the `<X>Lists` class.
