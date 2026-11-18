/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A comment form: Ctrl+Enter (Cmd+Enter on a Mac) in its text sends it, and
 * the internal switch shows in words who will see the comment. Without
 * JavaScript it is a plain form with a submit button.
 *
 * The task page refreshes by morphing after any other change on it. While the
 * form holds an unsent draft, that morph leaves the form as it is, so the draft
 * is never lost; sending it lets the morph through (as inline_field_controller).
 *
 *     <form data-controller="comment-form">
 *         <textarea data-action="keydown->comment-form#shortcut"></textarea>
 *         <input type="checkbox" data-comment-form-target="internal" data-action="change->comment-form#toggle">
 *         <span data-comment-form-target="internalLabel">…</span><span data-comment-form-target="publicLabel" hidden>…</span>
 */
export default class extends Controller {
    static targets = ['internal', 'internalLabel', 'publicLabel'];

    connect() {
        this.guard = (event) => {
            if (event.target === this.element && this.dirty && !this.sending) {
                event.preventDefault();
            }
        };
        this.sent = () => {
            this.sending = true;
        };
        document.addEventListener('turbo:before-morph-element', this.guard);
        this.element.addEventListener('submit', this.sent);
    }

    disconnect() {
        document.removeEventListener('turbo:before-morph-element', this.guard);
        this.element.removeEventListener('submit', this.sent);
    }

    get dirty() {
        const changed = (field) => field.value !== field.defaultValue;
        const switched = this.hasInternalTarget && this.internalTarget.checked !== this.internalTarget.defaultChecked;

        return switched || Array.from(this.element.querySelectorAll('textarea')).some(changed);
    }

    shortcut(event) {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && !event.isComposing) {
            event.preventDefault();
            this.element.requestSubmit();
        }
    }

    toggle() {
        if (!this.hasInternalTarget) {
            return;
        }
        const internal = this.internalTarget.checked;
        this.internalLabelTargets.forEach((label) => { label.hidden = !internal; });
        this.publicLabelTargets.forEach((label) => { label.hidden = internal; });
    }
}
