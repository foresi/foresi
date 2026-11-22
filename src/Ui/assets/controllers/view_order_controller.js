/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Manage views, for an owner (saved views D7): a view moves by its handle (a pointer) or by its
 * Move up / Move down buttons (one tap or a key, WCAG 2.2 SC 2.5.7), past the "More views" line
 * too, and each move is said in the live region, as Ui:ColumnPicker does. The views above the
 * line are tabs; past the most a list shows, Save order is off and says why. Save order sends
 * the rows' views[] in their order, the line's empty one among them, with no script needed.
 */
export default class extends Controller {
    static targets = ['list', 'row', 'divider', 'count', 'full', 'status'];
    static values = { max: Number, moved: String, tab: String, more: String, count: String };

    connect() {
        this.refresh();
    }

    up(event) {
        this.move(event.currentTarget, -1);
    }

    down(event) {
        this.move(event.currentTarget, 1);
    }

    move(button, step) {
        const row = button.closest('[data-view-order-target="row"]');
        const items = this.items();
        const next = items[items.indexOf(row) + step];
        if (!row || !next) {
            return;
        }
        if (step < 0) {
            next.before(row);
        } else {
            next.after(row);
        }
        this.moved(row);
        // At an end the pressed button is disabled: the focus goes to the other one, never lost.
        const pressed = row.querySelector(step < 0 ? '[data-view-order-up]' : '[data-view-order-down]');
        const other = row.querySelector(step < 0 ? '[data-view-order-down]' : '[data-view-order-up]');
        (pressed && !pressed.disabled ? pressed : other)?.focus();
    }

    grab(event) {
        if (event.button !== 0) {
            return;
        }
        const row = event.currentTarget.closest('[data-view-order-target="row"]');
        if (!row) {
            return;
        }
        event.preventDefault();
        row.setAttribute('data-dragging', '');
        const start = this.items().indexOf(row);
        const over = (moveEvent) => {
            const others = this.items().filter((other) => other !== row);
            const target = others.find((other) => {
                const box = other.getBoundingClientRect();
                return moveEvent.clientY >= box.top && moveEvent.clientY <= box.bottom;
            });
            if (!target) {
                const last = others[others.length - 1];
                if (last && moveEvent.clientY > last.getBoundingClientRect().bottom) {
                    last.after(row);
                }
                return;
            }
            const box = target.getBoundingClientRect();
            if (moveEvent.clientY < box.top + box.height / 2) {
                target.before(row);
            } else {
                target.after(row);
            }
        };
        const drop = () => {
            window.removeEventListener('pointermove', over);
            window.removeEventListener('pointerup', drop);
            window.removeEventListener('pointercancel', drop);
            row.removeAttribute('data-dragging');
            if (this.items().indexOf(row) !== start) {
                this.moved(row);
            }
        };
        window.addEventListener('pointermove', over);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
    }

    // The rows and the "More views" line between them, in the list's order.
    items() {
        return [...this.listTarget.children].filter((item) => this.rowTargets.includes(item) || item === this.dividerTarget);
    }

    tabs() {
        const items = this.items();

        return items.slice(0, items.indexOf(this.dividerTarget));
    }

    moved(row) {
        this.refresh();
        const place = this.tabs().includes(row) ? this.tabValue : this.moreValue;
        this.statusTarget.textContent = this.movedValue.replace('{label}', row.dataset.label).replace('{place}', place);
    }

    refresh() {
        const items = this.items();
        // The first view cannot go up, past All; the last cannot go down.
        this.rowTargets.forEach((row) => {
            const index = items.indexOf(row);
            const up = row.querySelector('[data-view-order-up]');
            const down = row.querySelector('[data-view-order-down]');
            if (up) {
                up.disabled = index === 0;
            }
            if (down) {
                down.disabled = index === items.length - 1;
            }
        });
        // All is a tab too.
        const count = this.tabs().length + 1;
        const full = count > this.maxValue;
        this.countTarget.textContent = this.countValue.replace('{count}', String(count));
        this.fullTarget.hidden = !full;
        // Save order sits in the sheet's footer, past this element.
        const save = this.element.closest('dialog')?.querySelector('[data-view-order-save]');
        if (save) {
            save.disabled = full;
        }
    }
}
