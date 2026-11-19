# Values: one renderer per value type

A value looks the same wherever it appears: in a list cell, on a detail page and in a form. Once a
person has seen a customer's role as a green badge in the list, they see the same badge on the
contact and on the chosen chip in its form.

## The rule

1. **One renderer.** `Ui:Value` shows a value. `Ui:DataTable` cells, `Ui:DescriptionList` and
   `Ui:FactsGrid` all hand their values to it, so no page writes its own markup for a value.
2. **The attribute decides the look, the page does not.** `Ui\Format\AttributeCells` formats an
   attribute of the catalogue (`Ui\Contract\Attribute`) by its type, once for lists and detail pages
   alike:
   - text, reference, number, money, date and datetime are text in the format of the language;
   - an **enum** is a `Ui:Badge` in the tone the attribute declares for the value
     (`Attribute::$tones`; values it does not list are neutral gray);
   - a **multi-enum** is one such badge per value;
   - a boolean is Yes or No; an e-mail, phone or web address is a link; a country is its name;
   - **nothing** (null, an empty text, an empty list) is `Ui:EmptyValue` ("Nezadáno"), never a dash
     and never blank.
3. **A detail page names the attribute.** It shows a catalogue value as
   `<twig:Ui:Value list="party.contacts" attribute="roles" :value="party.roles" />`, which formats
   the value as the list's column does. A value that is not in a catalogue is passed as a ready
   shape: a text, a badge `{label, tone}`, `{badges: [...]}` or `{links: [...]}`.
4. **Forms keep their controls but wear the same look.** `Ui:ChoiceChips` given
   `list="…" attribute="…"` shows a chosen chip in the tone of the value's badge.
5. **Tones are declared once.** A tone belongs on the attribute (`tones:`), not at the call site.
   A cell of its own (`cell:`) is only for what a type cannot say, such as a hint or an icon, and
   reads its tone from the same declaration.

## Why

Martin asked on 2026-11-19 that one value type always renders the same way. The contact's role was
comma text in the list, green badges on the detail and toggle chips in the form. The pattern follows
products that keep a field's look across views:
- Notion and Attio give each option of a select or multi-select field one colour. It shows the same
  in a table, on the record page and in the picker.
- Shopify Polaris and Atlassian's lozenge give a status one tone everywhere it appears.
- GitLab Pajamas and VA.gov never leave a table value blank. An empty value is said in words
  (`Ui:EmptyValue`).

## Checklist for a new value

- [ ] The attribute has the right `AttributeType`. An enum lists its `options`, and its `tones`
      where a value means something (green good, amber attention, red problem).
- [ ] The detail page shows it through `Ui:Value`, `Ui:DescriptionList` or `Ui:FactsGrid`, not
      through hand-written `<dd>` markup.
- [ ] A form choosing it with `Ui:ChoiceChips` names the same `list` and `attribute`.
