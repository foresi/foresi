/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const DELAY = 200;

/**
 * Text field with a listbox of matches from the server (WAI-ARIA combobox with
 * list autocomplete). Arrow keys, Home and End move through the options while the
 * focus stays in the field, Enter or a click picks, Escape and Tab close. Picking
 * fills the hidden value and sends "combobox:pick" with the whole result; typing
 * after a pick clears it and sends "combobox:clear".
 */
export default class extends Controller {
    static targets = ['value', 'input', 'list', 'status'];
    static values = { url: String, empty: String, found: String, minLength: { type: Number, default: 3 } };

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    search() {
        this.cancel();
        if (this.valueTarget.value !== '') {
            this.valueTarget.value = '';
            this.dispatch('clear');
        }
        const query = this.inputTarget.value.trim();
        if (query.length < this.minLengthValue && !/^\d+$/.test(query.replace(/\s+/g, ''))) {
            this.results = [];
            this.close();
            return;
        }
        this.timer = setTimeout(() => this.fetch(query), DELAY);
    }

    // An answer to an older text must not open the list over a newer one.
    cancel() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    async fetch(query) {
        this.request = new AbortController();
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('q', query.slice(0, 100));
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: this.request.signal });
            if (!response.ok) {
                this.close();
                return;
            }
            const data = await response.json();
            this.show(Array.isArray(data.results) ? data.results : []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.close();
            }
        }
    }

    show(results) {
        this.results = results;
        this.active = -1;
        this.listTarget.replaceChildren(...results.map((result, index) => this.option(result, index)));
        this.statusTarget.textContent = results.length === 0 ? this.emptyValue : this.foundValue.replace('__COUNT__', results.length);
        this.setOpen(results.length > 0);
    }

    option(result, index) {
        const item = document.createElement('li');
        item.id = `${this.inputTarget.id}-option-${index}`;
        item.setAttribute('role', 'option');
        item.setAttribute('aria-selected', 'false');
        item.className = 'flex cursor-pointer flex-wrap items-center gap-x-3 rounded-control px-2.5 py-1.5 text-ink hover:bg-head aria-selected:bg-head';
        const label = document.createElement('span');
        label.className = 'min-w-0 truncate font-medium';
        label.textContent = result.label;
        item.append(label);
        if (result.hint) {
            const hint = document.createElement('span');
            hint.className = 'num text-muted';
            hint.textContent = result.hint;
            item.append(hint);
        }
        // Before the blur of the field, which would close the list.
        item.addEventListener('mousedown', (event) => event.preventDefault());
        item.addEventListener('click', () => this.pick(result));
        return item;
    }

    navigate(event) {
        const count = this.listTarget.children.length;
        if (this.listTarget.hidden && event.key === 'ArrowDown' && count > 0) {
            this.setOpen(true);
            this.activate(0, event);
            return;
        }
        if (this.listTarget.hidden || count === 0) {
            return;
        }
        if (event.key === 'ArrowDown') {
            this.activate((this.active + 1) % count, event);
        } else if (event.key === 'ArrowUp') {
            this.activate((this.active - 1 + count) % count, event);
        } else if (event.key === 'Home') {
            this.activate(0, event);
        } else if (event.key === 'End') {
            this.activate(count - 1, event);
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.pick(this.results[this.active]);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        } else if (event.key === 'Tab') {
            this.close();
        }
    }

    activate(index, event) {
        event.preventDefault();
        Array.from(this.listTarget.children).forEach((item, position) => {
            item.setAttribute('aria-selected', position === index ? 'true' : 'false');
        });
        this.active = index;
        const item = this.listTarget.children[index];
        this.inputTarget.setAttribute('aria-activedescendant', item.id);
        item.scrollIntoView({ block: 'nearest' });
    }

    pick(result) {
        this.cancel();
        this.valueTarget.value = result.id;
        this.inputTarget.value = result.label;
        this.close();
        this.dispatch('pick', { detail: result });
    }

    close() {
        this.setOpen(false);
    }

    setOpen(open) {
        this.listTarget.hidden = !open;
        if (open) {
            this.active = -1;
        }
        this.inputTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (!open) {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }
}
