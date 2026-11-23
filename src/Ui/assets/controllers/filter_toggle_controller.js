/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The filter icon at the end of a list's tabs (Shopify Polaris IndexFilters):
 * it opens the list's filter bar under the tabs, and folds it again while
 * nothing narrows the list. A list without a bar (empty, nothing filtered)
 * shows no icon. Without JavaScript the bar is always open and the icon hidden.
 */
export default class extends Controller {
    connect() {
        this.bar = document.getElementById(this.element.getAttribute('aria-controls') ?? '');
        this.element.hidden = !this.bar;
        this.render();
    }

    toggle() {
        if (!this.bar) {
            return;
        }
        if (this.bar.hasAttribute('data-collapsed')) {
            this.bar.removeAttribute('data-collapsed');
            this.render();
            this.focusBar();
            return;
        }
        // A bar that narrows the list stays open: its chips say what is set.
        if (this.bar.hasAttribute('data-narrowed')) {
            this.focusBar();
            return;
        }
        this.bar.setAttribute('data-collapsed', '');
        this.render();
    }

    render() {
        if (this.bar) {
            this.element.setAttribute('aria-expanded', String(!this.bar.hasAttribute('data-collapsed')));
        }
    }

    focusBar() {
        const field = this.bar.querySelector('input[type="search"], input:not([type="hidden"]), button:not([hidden]), a[href]');
        field?.focus();
    }
}
