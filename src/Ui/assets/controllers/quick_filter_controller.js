/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const DELAY = 250;
const OPTION = 'group flex min-h-control w-full cursor-pointer items-center gap-2 rounded-option border-0 bg-transparent px-3 py-2.5 text-left font-medium text-ink no-underline transition-colors hover:bg-frame has-focus-visible:outline-2 has-focus-visible:-outline-offset-2 has-focus-visible:outline-green';
const BOX = 'size-4 shrink-0 accent-green';

/**
 * A quick filter under a list's tabs, "Stage ▾" (Shopify Polaris IndexFilters, Linear's and Notion's
 * filter chips): its chip opens a panel of the field's values. A checkbox applies at once, the list
 * reloading with the value added or taken away; a reference searches the records it points to with
 * the picker of its source (?q= answers {results: [{id, label, hint}]}, as Ui:Combobox reads it), the
 * first ones listed as the panel opens; a text takes its words in a small field, Enter applies them.
 * The link carries the working filter (f=) as the list writes it: base64url JSON of {match,
 * conditions}. Escape or a click outside closes the panel.
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
        if (!this.element.open) {
            return;
        }
        if (this.hasInputTarget) {
            this.inputTarget.focus();
        } else {
            this.element.querySelector('input, a')?.focus();
        }
        // A reference lists its first records before anything is typed.
        if (this.urlValue && this.hasResultsTarget && !this.loaded) {
            this.loaded = true;
            this.fetch('');
        }
    }

    keydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.element.open = false;
            this.element.querySelector('summary')?.focus();
        }
        if (event.key === 'ArrowDown' && this.hasResultsTarget) {
            event.preventDefault();
            this.resultsTarget.querySelector('input, a')?.focus();
        }
    }

    // A checkbox of a value: the list with it added or taken away, at once.
    go(event) {
        const href = event.currentTarget.dataset.href;
        if (href) {
            this.visit(href);
        }
    }

    // A text: its words on Enter, none clearing it.
    type(event) {
        if (event.key !== 'Enter') {
            this.keydown(event);
            return;
        }
        event.preventDefault();
        const words = this.inputTarget.value.trim();
        this.visit(this.href(words === '' ? [] : [words]));
    }

    search() {
        clearTimeout(this.timer);
        const query = this.inputTarget.value.trim();
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
            this.render(results, query);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.render([], query);
            }
        }
    }

    render(results, query) {
        const selected = this.selectedValue.map(String);
        // A short list with no search box (QuickPick::Choices) keeps its order, the picked ones
        // checked in place, as an enum's values; a search lists the picked ones above it.
        const inPlace = !this.hasInputTarget;
        const items = results
            .filter((result) => inPlace || !selected.includes(String(result.id)))
            .map((result) => {
                const item = document.createElement('li');
                const label = document.createElement('label');
                label.className = OPTION;
                const box = document.createElement('input');
                box.type = 'checkbox';
                box.className = BOX;
                box.checked = selected.includes(String(result.id));
                box.dataset.href = this.href(this.toggle(String(result.id)));
                box.dataset.action = 'change->quick-filter#go';
                const name = document.createElement('span');
                name.className = 'min-w-0 flex-1 truncate';
                name.textContent = result.label ?? '';
                // The name alone, as an enum's value reads; the picker's hint (an e-mail, a number) stays in the sheet.
                label.append(box, name);
                item.append(label);
                return item;
            });
        this.resultsTarget.replaceChildren(...items);
        this.statusTarget.textContent = items.length === 0 && query !== '' ? this.emptyValue : '';
    }

    // The values picked with this one added, or taken away; alone where the field takes one.
    toggle(id) {
        const selected = this.selectedValue.map(String);
        if (this.singleValue) {
            return [id];
        }
        return selected.includes(id) ? selected.filter((one) => one !== id) : [...selected, id];
    }

    href(values) {
        const conditions = JSON.parse(this.conditionsValue || '[]');
        if (values.length > 0) {
            const one = values.length === 1 && this.opValue !== 'has_any_of';
            conditions.push({ field: this.fieldValue, op: one && this.opValue !== 'contains' ? 'is' : this.opValue, value: one ? values[0] : values });
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

    visit(href) {
        if (window.Turbo) {
            window.Turbo.visit(href);
        } else {
            window.location.assign(href);
        }
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
