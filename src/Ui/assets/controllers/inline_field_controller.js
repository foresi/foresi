/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A text of a record changed where it is read, such as the title or the description of a task
 * (Linear, "Creating issues": click directly on the title or the description to edit it;
 * Atlassian, Inline edit). The read view and the form are both on the page; clicking the read
 * view, or the pencil button beside it, which is how a keyboard and a screen reader get there,
 * swaps them. Without JavaScript the pencil is a link to the page with the form open.
 *
 * A short text ("blur": "confirm") saves on Enter, as any form of one field does, cancels on
 * Escape and saves when the focus leaves it changed. A long text ("blur": "keep") stays open
 * when the focus leaves, and while it holds unsaved changes the page refreshed by another change
 * leaves it as it is (Atlassian, Inline edit: keepEditViewOpenOnBlur), so it is never lost.
 *
 *     <section data-controller="inline-field" data-inline-field-blur-value="keep">
 *       <a href="?edit=description" data-inline-field-target="pencil" data-action="inline-field#open">
 *       <div data-inline-field-target="read" data-action="click->inline-field#open">
 *       <form data-inline-field-target="form" hidden> <textarea data-inline-field-target="field">
 */
export default class extends Controller {
    static targets = ['read', 'form', 'field', 'pencil', 'text'];

    static values = { blur: { type: String, default: 'confirm' } };

    connect() {
        this.guard = (event) => {
            if (event.target === this.element && this.editing && this.dirty && !this.saving) {
                event.preventDefault();
            }
        };
        document.addEventListener('turbo:before-morph-element', this.guard);
    }

    disconnect() {
        document.removeEventListener('turbo:before-morph-element', this.guard);
    }

    get editing() {
        return this.hasFormTarget && !this.formTarget.hidden;
    }

    get dirty() {
        return this.hasFieldTarget && this.fieldTarget.value !== this.fieldTarget.defaultValue;
    }

    edit(event) {
        // A link, a checkbox or a picture in the text is its own; a selection is being read or copied.
        const target = event.target instanceof Element ? event.target : null;
        if (event.type === 'click' && target && !target.closest('[data-inline-field-target="pencil"]')) {
            if (target.closest('a, button, input, select, textarea, label, summary, img, video')) {
                return false;
            }
            if (String(window.getSelection?.() ?? '') !== '') {
                return false;
            }
        }
        event.preventDefault();

        return true;
    }

    open(event) {
        if (!this.edit(event) || this.editing) {
            return;
        }
        this.show(true);
        const field = this.fieldTarget;
        field.focus();
        const end = field.value.length;
        field.setSelectionRange?.(end, end);
    }

    cancel(event) {
        event?.preventDefault();
        this.formTarget.reset();
        this.show(false);
        this.focusPencil();
    }

    keydown(event) {
        if (event.key === 'Escape' && !event.defaultPrevented) {
            event.preventDefault();
            this.cancel();
        }
    }

    focusout(event) {
        if (this.blurValue !== 'confirm' || !this.editing || this.formTarget.contains(event.relatedTarget)) {
            return;
        }
        if (this.dirty) {
            this.formTarget.requestSubmit();
        } else {
            this.formTarget.reset();
            this.show(false);
        }
    }

    // What was typed reads at once; the page then comes back morphed with it saved, or with the form and its error.
    submit() {
        if (this.hasTextTarget && this.fieldTarget.value.trim() !== '') {
            this.textTarget.textContent = this.fieldTarget.value.trim();
            this.show(false);
            this.focusPencil();
        }
        this.saving = true;
    }

    show(editing) {
        this.saving = false;
        this.formTarget.hidden = !editing;
        this.readTargets.forEach((read) => {
            read.hidden = editing;
        });
        if (this.hasPencilTarget) {
            this.pencilTarget.hidden = editing;
        }
    }

    focusPencil() {
        if (this.hasPencilTarget) {
            this.pencilTarget.focus();
        }
    }
}
