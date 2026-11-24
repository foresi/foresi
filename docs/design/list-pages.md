# List pages: one set of controls

Every page that lists records (invoices, purchase documents, contacts, bank movements, recurring
invoices, tasks, projects, notifications, time, the send log, and every list added later) uses the
same controls in the same places. A person who learns one list knows them all.

## The rule

1. **Saved views are the tabs.** The tabs above a list are saved views (`ListDefinition` defaults
   synced by `app:views:sync`, plus the views people save), never hard-coded links. At most six
   views are tabs, All among them. The others are listed in the Settings sheet only; one of them
   opened shows as a tab while it is. A module declares its list in an
   `Application/View/<X>Lists` class that implements `ListDefinitionSource`.
2. **One toolbar row.** `_list_toolbar` draws one row on every list, with one rail under it: the
   view tabs on the left (`Ui:Tabs`, `rail: false`), and on the right `_list_tools`, in this order:
   the search field (`w-64`, always visible on a list that searches; on a phone an icon that opens
   the field over the row), the Filter icon and the Settings icon. A list without tabs (the time
   week, the board, documents, cash flow) has the same tools in the same spot. No list builds its
   own variant, and the row has no menu of views and no "+ Filter" button.
3. **Search and filters.** Every list searches on the server (`q=`). The Filter icon opens the
   `Ui:FilterBar` sheet at once: every condition with its operators, the builder's rows through
   "+ Přidat filtr". Under the tabs, the row of the filter bar shows the view's quick filters as
   solid chips, then any other condition set in the sheet as a chip with ✕, then "Zrušit vše",
   which clears every filter and the search. The row is hidden while the view pins no field and
   nothing is set. While a search or filter is set, the Filter icon has a green dot, and while the
   view has unsaved changes the row ends with Reset, Save as new view and Save to view, which keep
   the filters, the quick filters and the columns together (`_view_actions`, `_view_dialogs`).
   Changing filters, sort or display puts a dot on the tab until the change is saved or reset.
4. **Quick filters belong to the view.** A view pins up to four fields of its list as quick filters,
   kept in `display.pinned`; a view that keeps none (every shipped view) shows the defaults its
   `ListDefinition` declares (Deals: Fáze and Vlastník; Invoices: DUZP and Odběratel). A chip
   reads "Fáze ▾" and opens a dropdown of values by the field's type (`QuickPick`): an enum shows
   checkboxes that apply at once, several where the field takes "is any of"; a reference (people,
   records) a search box with checkboxes, its first results loaded on open; a date the periods and
   the months with records and "Vlastní…", which opens the sheet (the month is an ordinary date
   field, never a `month=` parameter); a text a small input. A set chip reads "Fáze: Nabídka ✕"
   ("Fáze: Nabídka, Schůzka ✕" truncated for several), and its ✕ clears its field alone. Fixed
   one-click filters ("Po splatnosti", "K odeslání", "Moje") are saved views on the tabs, not a
   second kind of chip.
5. **Settings sheet.** The Settings icon opens `Ui:ColumnPicker`, "Nastavení seznamu", with four
   parts: Pohledy (All locked first; drag or arrows to order; the checkbox puts a view on the tabs;
   the row's ⋯ menu opens, renames, shows the count on the tab, duplicates, shares, hides or
   deletes it; hidden views, "+ Nový pohled" and restoring the shipped views), Rychlé filtry (every
   filter field of the list, the checked ones first; check, drag or arrows; at most four), Sloupce
   and Hustota. "Obnovit výchozí" restores the defaults. Every list has a `<X>Columns` registry; the
   default columns are what the list showed before. A change is remembered per person per view
   (`RememberedDisplay`); a shared view is saved for everyone only by someone who may edit it.
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
- Shopify Polaris IndexFilters puts search and filter on the row of view tabs, its pinned filters
  as chips that open a picker of their field's values, and Save as and Save at the end.
- Linear and Notion keep a view's filters and display options with the view, and their filter chips
  are named by their field and open its values; Linear's display options sheet holds the columns
  and the ordering. Notion and Attio keep a view's properties, filters and sorts in one view
  settings menu, so the row itself stays tabs and a few icons.

The decisions are D1–D14 and D-display in the spec "Quick filters and saved views".

## Checklist for a new list

- [ ] `<X>Lists` (`ListDefinitionSource`) with fields and default views; `app:views:sync` lists it.
- [ ] Doctrine compiles conditions through `Shared\Infrastructure\View\DqlConditions`.
- [ ] The controller uses `ViewBar::of`, `FilterSheet::of`, `ViewState::sortParam` and
      `RememberedDisplay`, and redirects `FilterRows::MARKER` to `f=`.
- [ ] The template sets `search`, includes `_list_toolbar` and has the FilterBar with builder, the
      ColumnPicker and the dialogs, as `src/Invoicing/templates/index.html.twig` does.
- [ ] The list's search (`q=`) runs on the server.
- [ ] Tests cover the old URLs, the builder redirect, the dot, Save as new and the `<X>Lists` class.
