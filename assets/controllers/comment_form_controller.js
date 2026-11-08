/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A comment form: Ctrl+Enter (Cmd+Enter on a Mac) in its text sends it, and
 * the internal switch shows in words who will see the comment. Without
 * JavaScript it is a plain form with a submit button.
 *
 *     <form data-controller="comment-form">
 *         <textarea data-action="keydown->comment-form#shortcut"></textarea>
 *         <input type="checkbox" data-comment-form-target="internal" data-action="change->comment-form#toggle">
 *         <span data-comment-form-target="internalLabel">…</span><span data-comment-form-target="publicLabel" hidden>…</span>
 */
export default class extends Controller {
    static targets = ['internal', 'internalLabel', 'publicLabel'];

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
