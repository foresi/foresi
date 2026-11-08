/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A form of one property that saves as soon as a value is chosen. Without
 * JavaScript the form keeps its own Save button inside <noscript>.
 *
 *     <form data-controller="autosubmit" data-action="change->autosubmit#submit">
 */
export default class extends Controller {
    submit() {
        this.element.requestSubmit();
    }
}
