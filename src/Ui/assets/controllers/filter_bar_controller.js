/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Ui:FilterBar: "+ Filter", "More filters" and, on a phone, "Filters (n)" open
 * the bar's side sheet, which sends its fields with one Apply. The chips are
 * menus of links and need nothing more; a quick filter with no values to pick
 * from opens the sheet with a row for its field.
 *
 * In the sheet's filter builder (spec D11) a field picked from "Add filter"
 * adds its row, from the row the server rendered for it in a <template>; ✕
 * removes one. Each row shows only the controls its operator and date rule
 * use: a range shows its second end, an emptiness check no value.
 */
export default class extends Controller {
    static outlets = ['dialog'];

    connect() {
        this.added = 0;
        this.sync();
    }

    open(event) {
        if (this.hasDialogOutlet) {
            this.dialogOutlet.open(event.currentTarget);
        }
    }

    // A quick filter with no values to pick from, or "Custom…" of one: the sheet with a row for its field.
    pick(event) {
        this.open(event);
        this.addRow(event);
    }

    addRow(event) {
        const field = event.currentTarget.dataset.field;
        // The menu closes on a picked item, the focus going to the new row instead.
        const menu = event.currentTarget.closest('[data-controller~="dropdown"]');
        if (menu) {
            this.application.getControllerForElementAndIdentifier(menu, 'dropdown')?.close(false);
        }
        const template = this.element.querySelector(`template[data-filter-template="${CSS.escape(field)}"]`);
        const rows = this.element.querySelector('[data-filter-rows]');
        if (!template || !rows) {
            return;
        }
        // An index no row of the page has, so the new row's names and ids are its own.
        const index = `n${Date.now().toString(36)}${this.added++}`;
        const holder = document.createElement('div');
        holder.innerHTML = template.innerHTML.replaceAll('__i__', index);
        const row = holder.firstElementChild;
        rows.append(row);
        this.sync();
        row.querySelector('select, input:not([type="hidden"])')?.focus();
    }

    removeRow(event) {
        const row = event.currentTarget.closest('[data-filter-row]');
        const next = row?.nextElementSibling ?? row?.previousElementSibling;
        row?.remove();
        const focus = next?.querySelector('select') ?? this.element.querySelector('[data-filter-add] button');
        focus?.focus();
    }

    sync() {
        this.element.querySelectorAll('[data-filter-row]').forEach((row) => {
            const op = row.querySelector('[data-filter-op]')?.value ?? '';
            row.querySelectorAll('[data-hide-op]').forEach((part) => {
                part.hidden = part.dataset.hideOp.split(' ').includes(op);
            });
            row.querySelectorAll('[data-show-op]').forEach((part) => {
                part.hidden = !part.dataset.showOp.split(' ').includes(op);
            });
            row.querySelectorAll('[data-date-end]').forEach((end) => {
                const rule = end.querySelector('[data-filter-rule]')?.value ?? '';
                end.querySelectorAll('[data-show-rule]').forEach((part) => {
                    part.hidden = !part.dataset.showRule.split(' ').includes(rule);
                });
            });
        });
    }
}
