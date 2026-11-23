# List pages: one set of controls

Every page that lists records (invoices, purchase documents, contacts, bank movements, recurring
invoices, tasks, projects, notifications, time, the send log, and every list added later) uses the
same controls in the same places. A person who learns one list knows them all.

## The rule

1. **Saved views are the tabs.** The tabs above a list are saved views (`ListDefinition` defaults
   synced by `app:views:sync`, plus the views people save), never hard-coded links. At most six
   views are primary tabs. The rest sit under "More views" (`saved_view.more_views`). A module
   declares its list in an `Application/View/<X>Lists` class that implements `ListDefinitionSource`.
2. **One toolbar.** `Ui:Tabs` receives `:more` and the `_view_menu` actions block. At the end of the
   tabs `_list_tools` puts the same three icons on every list, in this order: Search (on a list that
   searches), Filter and Display. A list without tabs (the time week, the board, documents, cash
   flow) has the same icons in the same spot. No list builds its own variant.
3. **Filters.** The Filter icon opens the `Ui:FilterBar` sheet at once, in one click: every filter,
   the builder's rows through "+ Přidat filtr". Under the tabs, the row of the filter bar shows the
   view's quick filters as chips and any filter set; it is hidden while it has nothing to show. The
   Search icon opens the search at the start of that row. While a search or filter is set, the
   Filter icon has a green dot, and while the view has unsaved changes the row ends with Reset,
   Save as new view and Save to view, which keep the filters, the quick filters and the columns
   together (`_view_actions`, `_view_dialogs`). Changing filters, sort or display puts a dot on
   the tab until the change is saved or reset.
4. **Quick filters belong to the view.** A view pins up to four fields of its list as quick filters,
   kept in `display.pinned`; a view that keeps none (every shipped view) shows the defaults its
   `ListDefinition` declares. Each field declares how its chip's dropdown gets its values
   (`QuickPick`): its values (an enum, yes/no), picking several where the field takes "is any of";
   the periods and the months with records (a date; the month is an ordinary date field, never a
   `month=` parameter); a search of the records it points to (a reference, through its source's
   picker); or, with nothing to list, the sheet with a row for it. The chip reads "+ Field", then
   "Field: value ✕". Fixed one-click filters ("Po splatnosti", "K odeslání", "Moje") are saved
   views on the tabs, not a second kind of chip.
5. **Display sheet.** Every list has a `<X>Columns` registry and `Ui:ColumnPicker`, the Display
   sheet: Rychlé filtry (check and drag to order, with the presets named and Manage views beside
   them), Sloupce, Hustota řádků and "Obnovit výchozí". The default columns are what the list showed
   before. The display a person last used on a view is remembered (`RememberedDisplay`).
6. **The URL holds the working state.** It holds `view=`, `f=`, `q=`, `sort=`, `cols=` and `pins=`. A link
   can be shared and survives a reload. Old URLs keep working: a default view's slug equals the old
   `tab=` value, and an old parameter (`month=`, `client=`, `status=` …) becomes a condition in
   `f=`.
7. **Counts only where they help. A tab shows a count only where the list counted before, or where
   a person turns it on. Counts are summed in one query with the page.
8. **What is not a list.** A board, the time week, a dashboard, a settings hub (firm, people, API
   tokens) and an import preview keep plain `Ui:Tabs` as navigation. They are places, not lists of
   records (see [overlays](overlays.md)).

## Why

Martin asked for one behaviour on every list. The pattern follows products that people already
know:
- Linear and GitHub Projects keep views as tabs and applied filters in the URL. GitHub Projects
  marks an unsaved change with a dot.
- Attio, level.io and Shopify offer Save, Save as new and Reset.
- Attio, HubSpot and ABRA Flexi let any user pick the columns.
- Shopify Polaris IndexFilters puts search and filter icons on the row of view tabs, its pinned
  filters as chips that open a picker of their field's values, and Save as and Save at the end.
- Linear and Notion keep a view's filters and display options with the view, and their filter chips
  are named by their field and open its values; Linear's display options sheet holds the columns
  and the ordering.

The decisions are D1–D14 and D-display in the spec "Quick filters and saved views".

## Checklist for a new list

- [ ] `<X>Lists` (`ListDefinitionSource`) with fields and default views; `app:views:sync` lists it.
- [ ] Doctrine compiles conditions through `Shared\Infrastructure\View\DqlConditions`.
- [ ] The controller uses `ViewBar::of`, `FilterSheet::of`, `ViewState::sortParam` and
      `RememberedDisplay`, and redirects `FilterRows::MARKER` to `f=`.
- [ ] The template has the view bar, the FilterBar with builder, the ColumnPicker and the dialogs, as
      `src/Invoicing/templates/index.html.twig` does.
- [ ] Tests cover the old URLs, the builder redirect, the dot, Save as new and the `<X>Lists` class.
