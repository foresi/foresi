/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A date changed where it is shown (Ui:InlineEdit, kind "date"): the button opens the
 * browser's own date picker of the hidden field, and choosing a day or clearing it saves
 * the form at once. A browser that cannot open the picker from a button shows the field
 * in place of the button instead, focused, with the same saving.
 *
 *     <form data-controller="inline-date"> <button data-inline-date-target="button" data-action="inline-date#open">
 *     <input type="date" data-inline-date-target="input" data-action="change->inline-date#save">
 */
export default class extends Controller {
    static targets = ['button', 'input'];

    open() {
        const input = this.inputTarget;
        if (typeof input.showPicker === 'function') {
            try {
                input.showPicker();
                return;
            } catch {
                // Refused, such as without a user gesture: the field is shown instead.
            }
        }
        this.buttonTarget.hidden = true;
        input.classList.remove('sr-only');
        input.removeAttribute('tabindex');
        input.focus();
    }

    // The focus goes back to the button, which the page keeps when it comes back morphed.
    save() {
        this.buttonTarget.hidden = false;
        this.buttonTarget.focus();
        this.element.requestSubmit();
    }
}
