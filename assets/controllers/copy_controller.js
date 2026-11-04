/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const SHOWN = 2000;

/**
 * Copies the value of a read-only field to the clipboard and says so: the
 * button label turns into the "copied" text for a moment, and a polite live
 * region announces it. Where the clipboard is refused (an insecure origin, a
 * denied permission) the text is selected instead, so Ctrl+C still works.
 *
 *     <div data-controller="copy" data-copy-done-value="Zkopírováno">
 *         <input readonly value="…" data-copy-target="source">
 *         <button type="button" data-action="copy#copy"><span data-copy-target="label">Kopírovat</span></button>
 *         <span class="sr-only" aria-live="polite" data-copy-target="status"></span>
 *     </div>
 */
export default class extends Controller {
    static targets = ['source', 'label', 'status'];
    static values = { done: String, failed: String };

    async copy() {
        const text = this.sourceTarget.value;
        try {
            await navigator.clipboard.writeText(text);
        } catch {
            this.sourceTarget.focus();
            this.sourceTarget.select();
            this.announce(this.failedValue);
            return;
        }
        this.announce(this.doneValue);
        if (this.hasLabelTarget) {
            this.original ??= this.labelTarget.textContent;
            this.labelTarget.textContent = this.doneValue;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => { this.labelTarget.textContent = this.original; }, SHOWN);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    announce(message) {
        if (this.hasStatusTarget && message) {
            this.statusTarget.textContent = '';
            // A new text node, so the same message is announced again on a second copy.
            requestAnimationFrame(() => { this.statusTarget.textContent = message; });
        }
    }
}
