/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The filter icon at the end of a list's tabs (Shopify Polaris IndexFilters): it opens the list's
 * filter sheet at once, in one click, its green dot saying a filter is set. With no sheet to open it
 * is hidden; without JavaScript it is too, and the filter row with its <noscript> form is there.
 */
export default class extends Controller {
    connect() {
        const bar = document.getElementById(this.element.getAttribute('aria-controls') ?? '');
        this.sheet = bar ? document.getElementById(`${bar.id}-sheet`) : null;
        this.element.hidden = !this.sheet;
        const dot = this.element.parentElement?.querySelector('[data-filter-active]');
        if (dot) {
            dot.hidden = !bar?.hasAttribute('data-filters-set');
        }
    }

    toggle() {
        const dialog = this.sheet ? this.application.getControllerForElementAndIdentifier(this.sheet, 'dialog') : null;
        dialog?.open(this.element);
    }
}
