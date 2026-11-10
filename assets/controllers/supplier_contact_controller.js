/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The supplier of a received document: a contact created in the dialog the
 * form opened becomes its supplier, as the invoice form takes a new customer.
 *
 *     <div data-controller="supplier-contact" data-supplier-contact-url-value="/purchasing/suppliers"
 *          data-action="contact:created@document->supplier-contact#created">
 */
export default class extends Controller {
    static values = { url: String };

    async created(event) {
        const id = event.detail && event.detail.id;
        if (!id) {
            return;
        }
        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('id', id);
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        const supplier = response.ok ? (await response.json()).results[0] : undefined;
        if (supplier === undefined) {
            return;
        }
        this.element.querySelector('input[name="supplierId"]').value = supplier.id;
        const text = this.element.querySelector('input[name="supplierQuery"]');
        text.value = supplier.label;
        text.focus();
    }
}
