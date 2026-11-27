/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The deal's dialog while its client changes: when the client combobox picks
 * another contact ("combobox:pick"), the contact persons block is loaded for
 * it from the server, so the persons ticked for the client before are gone;
 * when the combobox is cleared ("combobox:clear"), the block goes. In "Add a
 * person" only the fields of the way chosen show: the address book's person,
 * or the new person's name and e-mail. Without JavaScript every field shows,
 * the server ignores the way not chosen, and a refused form comes back with
 * the persons of the client sent.
 *
 *     <form data-controller="deal-client" data-deal-client-url-value="/deals/client-persons">
 *         <div data-action="combobox:pick->deal-client#load combobox:clear->deal-client#clear">…</div>
 *         <div data-deal-client-target="persons">…</div>
 */
export default class extends Controller {
    static targets = ['persons', 'existing', 'new'];
    static values = { url: String };

    connect() {
        this.mode();
    }

    disconnect() {
        this.request?.abort();
    }

    async load(event) {
        const id = event.detail?.id;
        if (!id) {
            this.clear();
            return;
        }
        this.request?.abort();
        this.request = new AbortController();
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('client', id);
        try {
            const response = await fetch(url, { headers: { Accept: 'text/html' }, signal: this.request.signal });
            if (!response.ok) {
                this.clear();
                return;
            }
            this.personsTarget.innerHTML = await response.text();
            this.mode();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.clear();
            }
        }
    }

    clear() {
        this.request?.abort();
        if (this.hasPersonsTarget) {
            this.personsTarget.innerHTML = '';
        }
    }

    mode() {
        const chosen = this.element.querySelector('input[name="personMode"]:checked')?.value;
        if (this.hasExistingTarget) {
            this.existingTarget.hidden = chosen === 'new';
        }
        if (this.hasNewTarget) {
            this.newTarget.hidden = chosen === 'existing';
        }
    }
}
