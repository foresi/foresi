/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const INTERACTIVE = 'a, button, input, label, select, textarea, summary';

/**
 * Row selection for a data table: checkboxes, select all with an indeterminate
 * state, shift-click ranges and keyboard navigation. The bulk bar and its
 * live region are announced through the "status" target.
 */
export default class extends Controller {
    static targets = ['all', 'row', 'check', 'bar', 'count', 'status'];

    connect() {
        this.anchor = null;
        this.announced = null;
        this.ready = false;
        this.sync();
        this.ready = true;
    }

    toggleAll() {
        const checked = this.hasAllTarget && this.allTarget.checked;
        this.checkTargets.forEach((box) => {
            box.checked = checked;
        });
        this.anchor = null;
        this.sync();
    }

    // Runs on click, when the checkbox already has its new state.
    rowCheck(event) {
        const index = this.checkTargets.indexOf(event.currentTarget);
        if (event.shiftKey && this.anchor !== null && this.anchor !== index) {
            const [from, to] = [Math.min(this.anchor, index), Math.max(this.anchor, index)];
            this.checkTargets.slice(from, to + 1).forEach((box) => {
                box.checked = event.currentTarget.checked;
            });
        }
        this.anchor = index;
    }

    sync() {
        const boxes = this.checkTargets;
        const selected = boxes.filter((box) => box.checked);
        const count = selected.length;

        this.rowTargets.forEach((row) => {
            const box = row.querySelector('[data-table-selection-target~="check"]');
            if (box && box.checked) {
                row.dataset.selected = 'true';
            } else {
                delete row.dataset.selected;
            }
        });

        if (this.hasAllTarget) {
            this.allTarget.checked = boxes.length > 0 && count === boxes.length;
            this.allTarget.indeterminate = count > 0 && count < boxes.length;
        }

        if (this.hasBarTarget) {
            const appearing = this.barTarget.hidden && count > 0;
            this.barTarget.hidden = count === 0;
            const text = count === 0 ? '' : this.format(count);
            if (this.hasCountTarget) {
                this.countTarget.textContent = text;
            }
            if (this.ready && this.hasStatusTarget && this.announced !== count) {
                this.statusTarget.textContent = text;
            }
            if (appearing && this.ready) {
                this.revealFocus();
            }
        }
        this.announced = count;

        this.dispatch('change', { detail: { ids: selected.map((box) => box.value), count } });
    }

    // When the bar appears, keep the focused row clear of it (rows carry a bottom scroll margin).
    revealFocus() {
        const active = document.activeElement;
        const row = active && active.closest ? active.closest('[data-table-selection-target~="row"]') : null;
        if (row) {
            row.scrollIntoView({ block: 'nearest' });
        }
    }

    format(count) {
        const rule = new Intl.PluralRules(document.documentElement.lang || 'en').select(count);
        const data = this.barTarget.dataset;
        const template = data[`label${rule.charAt(0).toUpperCase()}${rule.slice(1)}`] || data.labelOther || '{count}';

        return template.replace('{count}', String(count));
    }

    // A click on the row itself (not on a control or selected text) follows its primary link;
    // a click in the checkbox cell toggles the checkbox. Modified and non-primary clicks are left to the browser.
    rowClick(event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.defaultPrevented) {
            return;
        }
        const row = event.target.closest('[data-table-selection-target~="row"]');
        if (!row) {
            return;
        }
        const box = row.querySelector('[data-table-selection-target~="check"]');
        const cell = event.target.closest('td');
        if (box && cell && cell.contains(box)) {
            if (!event.target.closest('label, input')) {
                box.click();
            }
            return;
        }
        if (event.target.closest(INTERACTIVE) || String(window.getSelection()).length > 0) {
            return;
        }
        const link = row.querySelector('[data-row-link]');
        if (link) {
            link.click();
        }
    }

    keydown(event) {
        if (event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        const target = event.target;
        if (this.hasAllTarget && target === this.allTarget) {
            if (event.key === 'ArrowDown' && this.checkTargets.length > 0) {
                event.preventDefault();
                this.checkTargets[0].focus();
            }
            return;
        }
        const row = target.closest('[data-table-selection-target~="row"]');
        if (!row) {
            return;
        }
        const box = row.querySelector('[data-table-selection-target~="check"]');

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.move(row, target, event.key === 'ArrowDown' ? 1 : -1);
        } else if (event.key === ' ' && box && target !== box && !target.matches('input, button, select, textarea')) {
            event.preventDefault();
            box.click();
        } else if (event.key === 'Enter' && target === box) {
            const link = row.querySelector('[data-row-link]');
            if (link) {
                event.preventDefault();
                link.click();
            }
        }
    }

    move(row, from, step) {
        const rows = this.rowTargets;
        const neighbour = rows[rows.indexOf(row) + step];
        if (!neighbour) {
            if (step < 0 && this.hasAllTarget) {
                this.allTarget.focus();
            }
            return;
        }
        const wantsLink = from.matches('[data-row-link]');
        const next = (wantsLink && neighbour.querySelector('[data-row-link]'))
            || neighbour.querySelector('[data-table-selection-target~="check"]')
            || neighbour.querySelector('[data-row-link]');
        if (next) {
            next.focus({ preventScroll: true });
            // The row carries a bottom scroll margin, so it never ends up under the bulk bar.
            neighbour.scrollIntoView({ block: 'nearest' });
        }
    }
}
