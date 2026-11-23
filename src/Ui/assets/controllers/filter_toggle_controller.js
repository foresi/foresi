/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The search and filter icons at the end of a list's tabs (Shopify Polaris IndexFilters):
 * the filter icon opens the list's filter sheet at once, in one click, its green dot saying a
 * search or a filter narrows the list; the search icon opens the search field at the start of the
 * filter row under the tabs, and folds it again while it holds no text. The row itself shows while
 * it has chips to show. An icon with nothing to open is hidden; without JavaScript both are, and
 * the row with its <noscript> form is always there.
 */
export default class extends Controller {
    static values = { mode: { type: String, default: 'filter' } };

    connect() {
        this.bar = document.getElementById(this.element.getAttribute('aria-controls') ?? '');
        this.sheet = this.bar ? document.getElementById(`${this.bar.id}-sheet`) : null;
        const search = this.bar?.querySelector('[data-filter-search]');
        this.element.hidden = this.modeValue === 'search' ? !search : !this.sheet;
        const dot = this.element.parentElement?.querySelector('[data-filter-active]');
        if (dot) {
            dot.hidden = !this.bar?.hasAttribute('data-filtered');
        }
        this.render();
    }

    // The filter icon: the sheet with every filter of the list.
    toggle() {
        const dialog = this.sheet ? this.application.getControllerForElementAndIdentifier(this.sheet, 'dialog') : null;
        dialog?.open(this.element);
    }

    search() {
        if (!this.bar) {
            return;
        }
        const form = this.bar.querySelector('[data-filter-search]');
        const field = form?.querySelector('input[type="search"]');
        if (form?.hasAttribute('data-folded') || this.bar.hasAttribute('data-collapsed')) {
            form?.removeAttribute('data-folded');
            this.bar.removeAttribute('data-collapsed');
            this.render();
            field?.focus();
            return;
        }
        // An empty search folds again, and the row with it while it has nothing else to show.
        if (field && field.value === '') {
            form.setAttribute('data-folded', '');
            if (!this.bar.hasAttribute('data-row')) {
                this.bar.setAttribute('data-collapsed', '');
            }
            this.render();
            return;
        }
        field?.focus();
    }

    render() {
        if (this.modeValue !== 'search' || !this.bar) {
            return;
        }
        const form = this.bar.querySelector('[data-filter-search]');
        this.element.setAttribute('aria-expanded', String(!!form && !form.hasAttribute('data-folded') && !this.bar.hasAttribute('data-collapsed')));
    }
}
