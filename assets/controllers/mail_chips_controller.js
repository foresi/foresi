/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Variable chips of a mail template. A chip is a button: a click, Enter or Space
 * inserts its token, such as {number}, at the caret of the field last in focus,
 * the body when none was, and focus stays on the chip, so the next one is a Tab
 * away. A chip can also be dragged into the text, where the browser drops the
 * token as plain text. The fields stay plain, so the token is what is saved and
 * what is read back, and the form works without JavaScript.
 *
 *     <section data-controller="mail-chips">
 *         <input data-mail-chips-target="field" data-action="focus->mail-chips#remember">
 *         <textarea data-mail-chips-target="field" data-action="focus->mail-chips#remember"></textarea>
 *         <button type="button" data-token="{number}" data-action="mail-chips#insert dragstart->mail-chips#drag">
 *         <p data-mail-chips-target="status" data-inserted="Inserted: {name}"></p>
 *     </section>
 */
export default class extends Controller {
    static targets = ['field', 'status'];

    connect() {
        this.last = this.fieldTargets.find((field) => field.tagName === 'TEXTAREA') ?? this.fieldTargets[0] ?? null;
    }

    remember(event) {
        this.last = event.currentTarget;
    }

    insert(event) {
        const field = this.last;
        const token = event.currentTarget.dataset.token;
        if (!field || !token) {
            return;
        }
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        field.setRangeText(token, start, end, 'end');
        field.dispatchEvent(new Event('input', { bubbles: true }));
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.statusTarget.dataset.inserted.replace('{name}', event.currentTarget.textContent.trim());
        }
    }

    drag(event) {
        event.dataTransfer.setData('text/plain', event.currentTarget.dataset.token);
        event.dataTransfer.effectAllowed = 'copy';
    }
}
