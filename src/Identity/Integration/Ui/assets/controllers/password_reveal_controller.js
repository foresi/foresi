/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Show passwords: one checkbox turns every password field of the form into
 * plain text and back (Google Account's "Show password"), so the person can
 * check what they typed instead of typing it twice. The checkbox waits hidden
 * until this runs, as it does nothing without it.
 *
 *     <form data-controller="password-reveal">
 *         <input type="password" data-password-reveal-target="input">
 *         <div data-password-reveal-target="toggle" hidden>
 *             <input type="checkbox" data-action="password-reveal#toggle"></div>
 */
export default class extends Controller {
    static targets = ['input', 'toggle'];

    connect() {
        this.toggleTargets.forEach((toggle) => {
            toggle.hidden = false;
        });
    }

    toggle(event) {
        const type = event.target.checked ? 'text' : 'password';
        this.inputTargets.forEach((input) => {
            input.type = type;
        });
    }
}
