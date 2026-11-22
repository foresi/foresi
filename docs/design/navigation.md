# Navigation: areas, sections and items

The navigation is a rail of business areas and, beside it, the sidebar of the area that holds the
page. Modules and plugins fill it through `Ui\Contract\Navigation`; no template lists items by hand.

## The rule

1. **An area defines its sections.** A `NavigationAreaProvider` yields `NavigationArea`s
   (`CoreNavigationAreas` for the core areas). An area may list its `NavigationSection`s, each with
   a key, a label key and a weight. An area that lists none has one section without a heading.
2. **An item names its area and a section of it.** `NavigationItem` takes the area id first and an
   optional `section` key. Within a section, items come by weight, then by registration order.
3. **Unknown is never an error.** An item that names no section, or one its area does not define,
   is listed in the area's last section, so a plugin can never break the sidebar. Only an area that
   no provider registers fails loudly.
4. **Headings from 7 items.** An area shows its section headings only when it has at least 7 items
   for the person (`NavigationArea::SECTION_HEADINGS_FROM`) and more than one of its sections holds
   an item. Below that it is one flat list in the same order. Empty sections are left out.
5. **One structure everywhere.** The desktop sidebar, the phone drawer and the command palette show
   the same sections: the palette lists the items of an area with headings under its section labels,
   and every other item under "Go to".

## The sections at launch

| Area     | Sections                                                                                   |
|----------|--------------------------------------------------------------------------------------------|
| Sales    | Selling: documents, products. Billing: invoices, advances, tax documents, corrections, recurring invoices, sent e-mails |
| Settings | Firm: firm, people, labels, documents. Connections: import, API and MCP, webhooks          |
| Others   | one section, no heading                                                                    |

## Adding an item

```php
yield new NavigationItem(
    CoreNavigationAreas::SALES,
    'nav.item.deals',
    'target',
    'app_deals',
    weight: 5,
    section: CoreNavigationAreas::SECTION_SELLING,
);
```

A plugin with an area of its own yields the area with its sections from a `NavigationAreaProvider`
and names those section keys on its items.
