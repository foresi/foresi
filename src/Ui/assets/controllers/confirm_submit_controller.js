/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A form that asks before it posts, on a page Turbo does not drive, where
 * data-turbo-confirm would never ask. The question is the shared confirmation
 * dialog's, from the same data-confirm-* attributes; on a page without it, the
 * browser asks. Without JavaScript the form posts as it is.
 *
 *     <form data-controller="confirm-submit" data-action="submit->confirm-submit#ask"
 *           data-confirm-title="Remove the payment?" data-confirm-tone="danger">
 */
export default class extends Controller {
    ask(event) {
        if (this.confirmed) {
            this.confirmed = false;
            return;
        }
        event.preventDefault();
        const { confirmTitle: title, confirmText: text, confirmAccept: accept, confirmCancel: cancel, confirmTone: tone } = this.element.dataset;
        new Promise((resolve) => {
            if (document.querySelector('[data-dialog-confirm-value="true"]') === null) {
                resolve(window.confirm(title));
                return;
            }
            document.dispatchEvent(new CustomEvent('dialog:confirm', { detail: { title, text, accept, cancel, tone, resolve } }));
        }).then((answer) => {
            if (answer) {
                this.confirmed = true;
                this.element.requestSubmit();
            }
        });
    }
}
