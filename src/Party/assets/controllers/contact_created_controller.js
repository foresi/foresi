/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * On the detail of a contact just created: tells an invoice form that opened the
 * contact form in another tab, which then picks the contact with its defaults.
 */
export default class extends Controller {
    static values = { id: String };

    connect() {
        const channel = new BroadcastChannel('foresi-contact-created');
        channel.postMessage(this.idValue);
        channel.close();
    }
}
