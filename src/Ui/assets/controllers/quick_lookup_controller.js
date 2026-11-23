/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const DELAY = 250;

/**
 * A quick filter that searches the records a field points to, such as "+ Customer" (Linear's and
 * Notion's filter chips, Shopify Polaris IndexFilters): its chip opens a panel with a search; each
 * record found is a link to the list with it picked, one at a time or added to those picked where
 * the field takes several. The picker of the field's source answers ?q= with {results: [{id, label,
 * hint}]}, as Ui:Combobox reads it. The link carries the working filter (f=) as the list writes it:
 * base64url JSON of {match, conditions}. Escape or a click outside closes the panel.
 */
export default class extends Controller {
    static targets = ['input', 'results', 'status'];
    static values = {
        url: String,
        base: String,
        param: String,
        conditions: String,
        field: String,
        op: String,
        single: Boolean,
        selected: Array,
        empty: String,
    };

    connect() {
        this.outside = (event) => {
            if (this.element.open && !this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        document.addEventListener('click', this.outside);
    }

    disconnect() {
        document.removeEventListener('click', this.outside);
        clearTimeout(this.timer);
    }

    toggled() {
        if (this.element.open) {
            this.inputTarget.focus();
        }
    }

    keydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.element.open = false;
            this.element.querySelector('summary')?.focus();
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.resultsTarget.querySelector('a')?.focus();
        }
    }

    search() {
        clearTimeout(this.timer);
        const query = this.inputTarget.value.trim();
        if (query === '') {
            this.resultsTarget.replaceChildren();
            this.statusTarget.textContent = '';
            return;
        }
        this.timer = setTimeout(() => this.fetch(query), DELAY);
    }

    async fetch(query) {
        this.request?.abort();
        this.request = new AbortController();
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('q', query.slice(0, 100));
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.request.signal });
            const { results = [] } = response.ok ? await response.json() : {};
            this.render(results);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.render([]);
            }
        }
    }

    render(results) {
        const items = results.map((result) => {
            const item = document.createElement('li');
            const link = document.createElement('a');
            link.href = this.href(String(result.id));
            link.className = 'flex min-h-touch items-center gap-2 rounded-control px-2 text-ink no-underline hover:bg-head focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-green';
            const label = document.createElement('span');
            label.className = 'min-w-0 flex-1 truncate';
            label.textContent = result.label ?? '';
            link.append(label);
            if (result.hint) {
                const hint = document.createElement('span');
                hint.className = 'shrink-0 text-small text-muted';
                hint.textContent = result.hint;
                link.append(hint);
            }
            item.append(link);
            return item;
        });
        this.resultsTarget.replaceChildren(...items);
        this.statusTarget.textContent = items.length === 0 ? this.emptyValue : '';
    }

    // The list with this record picked: alone, or added to those picked where the field takes several.
    href(id) {
        const selected = this.selectedValue.map(String);
        const values = this.singleValue ? [id] : selected.includes(id) ? selected.filter((one) => one !== id) : [...selected, id];
        const conditions = JSON.parse(this.conditionsValue || '[]');
        if (values.length > 0) {
            const one = values.length === 1 && this.opValue !== 'has_any_of';
            conditions.push({ field: this.fieldValue, op: one ? 'is' : this.opValue, value: one ? values[0] : values });
        }
        const url = new URL(this.baseValue, window.location.origin);
        if (conditions.length === 0) {
            url.searchParams.delete(this.paramValue);
        } else {
            url.searchParams.set(this.paramValue, this.encode({ match: 'all', conditions }));
        }
        url.searchParams.delete('page');
        return url.pathname + url.search;
    }

    encode(filter) {
        const bytes = new TextEncoder().encode(JSON.stringify(filter));
        let binary = '';
        bytes.forEach((byte) => {
            binary += String.fromCharCode(byte);
        });
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
}
