/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Variable chips of a mail template, in a toolbar: one Tab stop, the arrow keys,
 * Home and End move between the chips (a roving tabindex). A click, Enter or Space
 * inserts the chip's token, such as {Číslo dokladu}, at the caret of the field
 * last in focus, the body when none was, and focus returns to the chip, so the
 * next one is an arrow key away. The insert goes through the browser's own
 * editing (insertText), so Ctrl+Z or Cmd+Z in the field undoes it. A chip can
 * also be dragged into the text. The fields stay plain, so the form works
 * without JavaScript. A field marked data-mail-chips-default takes a chip
 * before any field was in focus.
 *
 *     <section data-controller="mail-chips">
 *         <input data-mail-chips-target="field" data-action="focus->mail-chips#remember">
 *         <textarea data-mail-chips-target="field" data-action="focus->mail-chips#remember"></textarea>
 *         <div role="toolbar" data-action="keydown->mail-chips#move">
 *             <button type="button" tabindex="0" data-token="{…}" data-mail-chips-target="chip"
 *                     data-action="mail-chips#insert dragstart->mail-chips#drag">
 *         </div>
 *         <p data-mail-chips-target="status" data-inserted="Inserted: {name}"></p>
 *     </section>
 */
export default class extends Controller {
    static targets = ['field', 'status', 'chip'];

    connect() {
        this.last = this.fieldTargets.find((field) => field.hasAttribute('data-mail-chips-default'))
            ?? this.fieldTargets.find((field) => field.tagName === 'TEXTAREA') ?? this.fieldTargets[0] ?? null;
    }

    remember(event) {
        this.last = event.currentTarget;
    }

    insert(event) {
        const chip = event.currentTarget;
        const field = this.last;
        const token = chip.dataset.token;
        if (!field || !token) {
            return;
        }
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        field.focus({ preventScroll: true });
        field.setSelectionRange(start, end);
        // insertText keeps the browser's undo history; setRangeText is the fallback without it.
        if (!document.execCommand('insertText', false, token)) {
            field.setRangeText(token, start, end, 'end');
            field.dispatchEvent(new Event('input', { bubbles: true }));
        }
        this.focusChip(chip);
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = this.statusTarget.dataset.inserted.replace('{name}', chip.textContent.trim());
        }
    }

    move(event) {
        const chips = this.chipTargets;
        const index = chips.indexOf(event.target);
        if (index < 0) {
            return;
        }
        const next = {
            ArrowRight: index + 1,
            ArrowDown: index + 1,
            ArrowLeft: index - 1,
            ArrowUp: index - 1,
            Home: 0,
            End: chips.length - 1,
        }[event.key];
        if (next === undefined) {
            return;
        }
        event.preventDefault();
        this.focusChip(chips[(next + chips.length) % chips.length]);
    }

    drag(event) {
        event.dataTransfer.setData('text/plain', event.currentTarget.dataset.token);
        event.dataTransfer.effectAllowed = 'copy';
    }

    focusChip(chip) {
        for (const other of this.chipTargets) {
            other.tabIndex = other === chip ? 0 : -1;
        }
        chip.focus({ preventScroll: true });
    }
}
