/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A form of one property that saves as soon as a value is chosen. Without
 * JavaScript the form keeps its own Save button inside <noscript>.
 *
 *     <form data-controller="autosubmit" data-action="change->autosubmit#submit">
 *
 * On an element inside a form, a change of what it holds sends the whole form.
 */
export default class extends Controller {
    submit() {
        const form = this.element instanceof HTMLFormElement ? this.element : this.element.closest('form');
        form?.requestSubmit();
    }
}
