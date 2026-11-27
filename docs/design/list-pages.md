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
8. **Every table is a list with views.** Every table of records in Foresi is a list as above: its
   saved views, a Settings sheet with columns, column widths a person drags
   (`Ui:DataTable :resizable="true"`) and Reset to default. A settings hub (firm, people, API tokens)
   keeps plain `Ui:Tabs` for its *navigation*, as a board, the time week and a dashboard do; any table
   inside it is a list. (Martin's decision of 2026-11-26; it replaces "settings hubs and the import
   preview are not lists".)
9. **Several tables on one page each keep their own state (list slots).** Each table on a page has
   its own views, filters, search, columns and widths, as each linked or inline database on
   a Notion page has its own views. The page's first table keeps the bare keys of rule 6 (`view=`,
   `f=`, `q=`, `sort=`, `cols=`, `group=`, `pins=`, `widths=` and the builder's `c[…]`),
   so old links keep working; every other table keeps the same keys nested under its slot, as
   `reasons[cols]=name&reasons[widths]=name:240`. A link or form of one table carries the other
   tables' state unchanged, and each table's dialogs have ids of their own. `ListSlot` does it:
   `ListSlot::first($page, 'reasons')`, `ListSlot::named('reasons', $page)`.
10. **A sub-table is keyed per table type; the parent record is context.** A table on a record's
   page (the contact people of a party, the lines of a deal, subtasks) has one list key for its
   type, such as `party.contact_people`: its views and each person's remembered display apply on
   every record of that type, as a HubSpot association table card and a Salesforce dynamic related
   list are set up once for the object. The record the table belongs to is a fixed condition the
   query adds, never a condition of a view, as `work.project_tasks` on the project page does.

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
- Several tables with views on one page (rule 9): Notion keeps views apart per database view on a
  page. "Each database view has its own settings" ([Notion: views, filters, sorts & groups](https://www.notion.com/help/views-filters-and-sorts)),
  and in a linked database "filters and views only apply to the linked instance"
  ([Notion: using linked databases](https://www.notion.com/help/guides/using-linked-databases)).
- Sub-tables keyed per table type (rule 10): HubSpot's record cards let an admin "select properties
  to display as columns in the association table", in the object's settings
  ([HubSpot: create cards to display data on records](https://knowledge.hubspot.com/object-settings/create-cards-on-records)).
  Salesforce's dynamic related lists let an admin "choose the list's columns, sort order, actions"
  on the object's record page, the record shown as the parent of the list
  ([Trailhead: optimize Lightning dynamic related lists](https://trailhead.salesforce.com/content/learn/projects/upgrade-to-dynamic-related-lists/get-started-with-dynamic-related-lists)).

The decisions are D1–D14 and D-display in the spec "Quick filters and saved views".

## Checklist for a new list

- [ ] `<X>Lists` (`ListDefinitionSource`) with fields and default views; `app:views:sync` lists it.
- [ ] Doctrine compiles conditions through `Shared\Infrastructure\View\DqlConditions`.
- [ ] The controller uses `ViewBar::of`, `FilterSheet::of`, `ViewState::sortParam` and
      `RememberedDisplay`, and redirects `FilterRows::MARKER` to `f=`.
- [ ] The template sets `search`, includes `_list_toolbar` and has the FilterBar with builder, the
      ColumnPicker and the dialogs, as `src/Invoicing/templates/index.html.twig` does.
- [ ] The list's search (`q=`) runs on the server.
- [ ] The table passes `:resizable="true"`, and its Reset adds `WorkingDisplay::WIDTHS => ''` (how
      tall the rows are is the person's density, Profile → Appearance, no state of the list).
- [ ] A short table whose records the page holds in memory (the settings tables) builds itself with
      `Ui\View\ListTable` (`built()` for the builder's Apply, `of()` for the table, its filter sheet
      added with `ListTablePage::with()`), as `LabelController` and `SalesSettingsController` do.
- [ ] On a page with several tables, each takes its `ListSlot` (rule 9) through `RememberedDisplay`
      (`$slot->query()`), `ViewBar::of`, `FilterSheet::of`, its picker (`slot`), its FilterBar keep and
      search name and `Ui:DataTable widthsParam`, as `SalesSettingsController` does.
- [ ] Tests cover the old URLs, the builder redirect, the dot, Save as new and the `<X>Lists` class.
