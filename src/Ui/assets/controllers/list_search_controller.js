/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The search field on the row of a list's tabs: always there on a wider screen; on a phone an icon
 * that opens the field over the row, which folds again when it loses the focus with no text in it
 * or on Escape. Enter sends the hidden form of the list's filter bar (the field's form attribute).
 */
export default class extends Controller {
    static targets = ['field', 'input', 'button'];

    open() {
        this.fieldTarget.setAttribute('data-open', '');
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        this.inputTarget.focus();
    }

    close() {
        this.fieldTarget.removeAttribute('data-open');
        this.buttonTarget.setAttribute('aria-expanded', 'false');
    }

    keydown(event) {
        if (event.key === 'Escape' && this.inputTarget.value === '') {
            this.close();
            this.buttonTarget.focus();
        }
    }

    blur() {
        if (this.inputTarget.value === '') {
            this.close();
        }
    }
}
