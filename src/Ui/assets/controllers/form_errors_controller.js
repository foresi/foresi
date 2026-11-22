/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A form the server sent back with errors: moves the keyboard focus into the
 * first invalid field (for a group of options, its first option) and scrolls
 * it to the middle of the viewport, so the floating action bar never covers
 * it. Attached to the body, so it runs on every page the server renders,
 * also when Turbo renders the 422 answer of a failed submission in place.
 * Without JavaScript the server's autofocus on the same field does the job.
 */
export default class extends Controller {
    connect() {
        this.focusFirstError();
        // Turbo scrolls to the top after it rendered a failed submission; move again once it has.
        this.frame = requestAnimationFrame(() => this.focusFirstError());
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
    }

    focusFirstError() {
        const control = this.element.querySelector('form [aria-invalid="true"]');
        if (control === null) {
            return;
        }
        if (document.activeElement !== control) {
            control.focus({ preventScroll: true });
        }
        control.scrollIntoView({ block: 'center', inline: 'nearest' });
    }
}
