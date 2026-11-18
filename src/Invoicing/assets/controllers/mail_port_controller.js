/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Choosing the encryption of a mail server presets the port it usually runs on:
 * 587 for STARTTLS, 465 for TLS. The port stays a field of its own, so a server
 * on 2525 is chosen after the encryption.
 *
 *     <form data-controller="mail-port" data-mail-port-ports-value='{"starttls":"587","tls":"465"}'>
 *         <select data-mail-port-target="port">…</select>
 *         <select data-action="change->mail-port#preset">…</select>
 */
export default class extends Controller {
    static targets = ['port'];
    static values = { ports: Object };

    preset(event) {
        const port = this.portsValue[event.currentTarget.value];
        if (port && this.hasPortTarget) {
            this.portTarget.value = port;
            this.portTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
