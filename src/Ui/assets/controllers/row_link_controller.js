/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const INTERACTIVE = 'a, button, input, label, select, textarea, summary, [role="menu"]';

/**
 * A click anywhere on a table row follows the row's one real link (its first cell), as Shopify
 * Polaris IndexTable does with its primary link. Keyboard and screen reader users keep that link;
 * the row adds nothing to the accessibility tree. Links, chips, buttons and selected text inside
 * the row keep their own behaviour; Ctrl or Cmd opens the record in a new tab, like the link would.
 */
export default class extends Controller {
    click(event) {
        if (event.button !== 0 || event.shiftKey || event.altKey || event.defaultPrevented) {
            return;
        }
        // The grip of a row put in order by hand drags it; it never opens the row.
        if (event.target.closest(INTERACTIVE) || event.target.closest('[data-row-reorder-handle]') || String(window.getSelection()).length > 0) {
            return;
        }
        const row = event.target.closest('tr');
        if (!row || !this.element.contains(row)) {
            return;
        }
        // The checkbox cell of a selectable table toggles its checkbox instead.
        const cell = event.target.closest('td');
        if (cell && cell.querySelector('input[type="checkbox"]')) {
            return;
        }
        const link = row.querySelector('[data-row-link]');
        if (!link) {
            return;
        }
        if (event.metaKey || event.ctrlKey) {
            window.open(link.href, '_blank', 'noopener');
            return;
        }
        link.click();
    }
}
