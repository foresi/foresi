/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const MIN_LENGTH = 3;
const NUMBER_LENGTH = 8;
const DELAY = 300;
const TOO_MANY_REQUESTS = 429;

/**
 * Registry lookup of the contact form: typing a registration number or the
 * start of a name offers matching companies; choosing one fills the name, the
 * identifiers and the billing address. The form works without it.
 */
export default class extends Controller {
    static targets = ['query', 'results', 'name', 'registrationCountry', 'registrationNumber', 'vatId', 'street', 'city', 'postalCode', 'countryCode'];
    static values = { url: String, existing: String, empty: String, limited: String };

    disconnect() {
        clearTimeout(this.timer);
        if (this.request) {
            this.request.abort();
        }
    }

    search() {
        clearTimeout(this.timer);
        const query = this.queryTarget.value.trim();
        const digits = query.replace(/\s+/g, '');
        const isNumber = /^\d+$/.test(digits);
        if (isNumber ? digits.length !== NUMBER_LENGTH : query.length < MIN_LENGTH) {
            this.clear();
            return;
        }
        this.timer = setTimeout(() => this.fetch(query), DELAY);
    }

    async fetch(query) {
        if (this.request) {
            this.request.abort();
        }
        this.request = new AbortController();
        try {
            const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query.slice(0, 100))}`, {
                headers: { Accept: 'application/json' },
                signal: this.request.signal,
            });
            if (response.status === TOO_MANY_REQUESTS) {
                this.note(this.limitedValue);
                return;
            }
            if (!response.ok) {
                this.clear();
                return;
            }
            const data = await response.json();
            this.show(Array.isArray(data.results) ? data.results : []);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.clear();
            }
        }
    }

    show(results) {
        this.resultsTarget.replaceChildren();
        if (results.length === 0) {
            this.note(this.emptyValue);
            return;
        }

        const list = document.createElement('ul');
        list.className = 'm-0 grid list-none gap-1 p-0';
        for (const record of results) {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'flex w-full min-h-6 flex-wrap items-center gap-x-3 rounded-control border border-control-line bg-white px-2.5 py-1.5 text-left text-ink hover:bg-head focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green';
            const name = document.createElement('span');
            name.className = 'truncate font-medium';
            name.textContent = record.name;
            name.title = record.name;
            const number = document.createElement('span');
            number.className = 'num text-muted';
            number.textContent = record.registrationNumber;
            button.append(name, number);
            button.addEventListener('click', () => this.choose(record));
            item.append(button);
            if (record.existing) {
                const link = document.createElement('a');
                link.href = record.existing.url;
                link.target = '_blank';
                link.rel = 'noopener';
                link.className = 'text-small text-badge-amber-text underline';
                link.textContent = `${this.existingValue}: ${record.existing.name}`;
                item.append(link);
            }
            list.append(item);
        }
        this.resultsTarget.append(list);
    }

    note(text) {
        this.resultsTarget.replaceChildren();
        const note = document.createElement('p');
        note.className = 'text-small text-muted';
        note.textContent = text;
        this.resultsTarget.append(note);
    }

    choose(record) {
        this.fill(this.registrationCountryTarget, record.registrationCountry);
        this.fill(this.registrationNumberTarget, record.registrationNumber);
        this.fill(this.nameTarget, record.name);
        this.fill(this.vatIdTarget, record.vatId || '');
        this.fill(this.streetTarget, record.street);
        this.fill(this.cityTarget, record.city);
        this.fill(this.postalCodeTarget, record.postalCode);
        this.fill(this.countryCodeTarget, record.countryCode);
        this.clear();
        this.nameTarget.focus();
    }

    fill(field, value) {
        field.value = value;
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    clear() {
        this.resultsTarget.replaceChildren();
    }
}
