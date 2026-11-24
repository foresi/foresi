/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Ui:ColumnPicker, the list's Settings sheet: its button opens the sheet; a row of the views, the
 * quick filters or the columns moves within its own list by its handle (a pointer:
 * mouse, pen or touch) or by its Move up / Move down buttons (one tap or a key, WCAG 2.2 SC
 * 2.5.7), and each move is said in the live region, as Atlassian's drag and drop guidance
 * asks. The locked rows lead and never move. A column checked under "Add column" comes after
 * the shown ones. Apply sends the checked columns in their order as one cols=a,b,c and the
 * pinned quick filters as one pins=a,b (empty for none), and without this controller the boxes
 * send cols[] and pins[] in the same order. At most maxPins quick filters are checked at once.
 * An owner's views: the checked ones are tabs, at most maxTabs with All; when their order or tabs
 * changed, Apply first posts them, tabs before the empty marker, to the order of the views.
 */
export default class extends Controller {
    static outlets = ['dialog'];
    static targets = ['form', 'list', 'row', 'option', 'add', 'box', 'lockedKey', 'keys', 'status', 'search', 'none', 'pinRow', 'pinBox', 'pins', 'pinMarker', 'viewRow', 'viewBox', 'viewOrder', 'tabsFull'];
    static values = { moved: String, maxPins: { type: Number, default: 4 }, maxTabs: { type: Number, default: 0 } };

    connect() {
        this.refresh();
        this.limitPins();
        this.limitTabs();
        this.views = this.viewOrder();
    }

    // All is a tab too: at most maxTabs - 1 others are checked.
    limitTabs() {
        if (this.maxTabsValue < 1) {
            return;
        }
        const checked = this.viewBoxTargets.filter((box) => box.checked).length;
        const full = checked >= this.maxTabsValue - 1;
        this.viewBoxTargets.forEach((box) => {
            box.disabled = !box.checked && full;
        });
        if (this.hasTabsFullTarget) {
            this.tabsFullTarget.hidden = !full;
        }
    }

    // The views top to bottom, the tabs before the empty marker, as the order of the views takes them.
    viewOrder() {
        const rows = this.viewRowTargets;
        const tabs = rows.filter((row) => row.querySelector('[data-column-picker-target="viewBox"]')?.checked).map((row) => row.dataset.ref);
        const rest = rows.filter((row) => !row.querySelector('[data-column-picker-target="viewBox"]')?.checked).map((row) => row.dataset.ref);
        return [...tabs, '', ...rest];
    }

    // The quick filters checked reach maxPins: the others wait, so a fifth is never lost silently.
    limitPins() {
        const checked = this.pinBoxTargets.filter((box) => box.checked).length;
        this.pinBoxTargets.forEach((box) => {
            box.disabled = !box.checked && checked >= this.maxPinsValue;
        });
    }

    open(event) {
        if (this.hasDialogOutlet) {
            this.dialogOutlet.open(event.currentTarget);
        }
    }

    up(event) {
        this.move(event.currentTarget, -1);
    }

    down(event) {
        this.move(event.currentTarget, 1);
    }

    move(button, step) {
        const row = button.closest('[data-movable]');
        const rows = this.movable(row);
        const index = rows.indexOf(row);
        const next = rows[index + step];
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
        const pressed = row.querySelector(step < 0 ? '[data-column-picker-up]' : '[data-column-picker-down]');
        const other = row.querySelector(step < 0 ? '[data-column-picker-down]' : '[data-column-picker-up]');
        (pressed && !pressed.disabled ? pressed : other)?.focus();
    }

    grab(event) {
        if (event.button !== 0) {
            return;
        }
        const row = event.currentTarget.closest('[data-movable]');
        if (!row) {
            return;
        }
        event.preventDefault();
        row.setAttribute('data-dragging', '');
        const start = this.position(row);
        // On the window: moving the row in the list ends a pointer capture on its handle.
        const over = (moveEvent) => {
            const others = this.movable(row).filter((other) => other !== row);
            if (others.length === 0) {
                return;
            }
            const first = others[0].getBoundingClientRect();
            const last = others[others.length - 1].getBoundingClientRect();
            if (moveEvent.clientY < first.top) {
                others[0].before(row);
                return;
            }
            if (moveEvent.clientY > last.bottom) {
                others[others.length - 1].after(row);
                return;
            }
            const target = others.find((other) => {
                const box = other.getBoundingClientRect();
                return moveEvent.clientY >= box.top && moveEvent.clientY <= box.bottom;
            });
            if (!target) {
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
            if (this.position(row) !== start) {
                this.moved(row);
            }
        };
        window.addEventListener('pointermove', over);
        window.addEventListener('pointerup', drop);
        window.addEventListener('pointercancel', drop);
    }

    search(event) {
        const text = event.currentTarget.value.trim().toLocaleLowerCase();
        let shown = 0;
        let options = 0;
        [...this.rowTargets, ...this.optionTargets].forEach((row) => {
            const match = text === '' || row.dataset.label.toLocaleLowerCase().includes(text);
            row.hidden = !match;
            shown += match ? 1 : 0;
            options += match && this.optionTargets.includes(row) ? 1 : 0;
        });
        if (this.hasAddTarget) {
            this.addTarget.hidden = options === 0;
        }
        if (this.hasNoneTarget) {
            this.noneTarget.hidden = shown > 0;
        }
    }

    submit(event) {
        const order = this.viewOrder();
        if (this.hasViewOrderTarget && order.join('\n') !== this.views.join('\n') && !this.saved) {
            // The views first, then the rest of the sheet to the list.
            event.preventDefault();
            this.saveViews(order);
            return;
        }
        // One readable cols=a,b,c instead of a cols[] per column.
        // The shown columns in their order, then the ones checked under "Add column".
        const keys = [...this.formTarget.querySelectorAll('[data-column-picker-target="lockedKey"], [data-column-picker-target="box"]:checked')].map((input) => input.value);
        if (this.hasKeysTarget) {
            this.keysTarget.value = keys.join(',');
            this.keysTarget.disabled = false;
        }
        [...this.lockedKeyTargets, ...this.boxTargets].forEach((input) => input.removeAttribute('name'));
        if (this.hasPinsTarget) {
            // The checked ones in their order.
            const pins = [...this.formTarget.querySelectorAll('[data-column-picker-target="pinBox"]:checked')].map((input) => input.value);
            this.pinsTarget.value = pins.join(',');
            this.pinsTarget.disabled = false;
            [...this.pinBoxTargets, ...this.pinMarkerTargets].forEach((input) => input.removeAttribute('name'));
        }
    }

    async saveViews(order) {
        const body = new FormData(this.viewOrderTarget);
        order.forEach((ref) => body.append('views[]', ref));
        try {
            await fetch(this.viewOrderTarget.action, { method: 'POST', body, headers: { Accept: 'text/html' }, redirect: 'manual' });
        } finally {
            this.saved = true;
            this.formTarget.requestSubmit();
        }
    }

    // The rows of the list a row is in that move: the columns but the locked one, or the quick filters.
    movable(row) {
        const list = row?.parentElement;
        return list ? [...list.children].filter((other) => other.hasAttribute('data-movable')) : [];
    }

    rows(row) {
        const list = row?.parentElement;
        return list ? [...list.children].filter((other) => other.matches('[data-column-picker-target="row"], [data-column-picker-target="pinRow"], [data-column-picker-target="viewRow"]')) : [];
    }

    position(row) {
        return this.rows(row).indexOf(row) + 1;
    }

    moved(row) {
        this.refresh();
        this.statusTarget.textContent = this.movedValue
            .replace('{label}', row.dataset.label)
            .replace('{position}', String(this.position(row)))
            .replace('{total}', String(this.rows(row).length));
    }

    // The first movable row of each list cannot go up, the last cannot go down.
    refresh() {
        const lists = [...this.element.querySelectorAll('[data-column-picker-list]')];
        lists.forEach((list) => this.refreshList([...list.children].filter((row) => row.hasAttribute('data-movable'))));
    }

    refreshList(rows) {
        rows.forEach((row, index) => {
            const up = row.querySelector('[data-column-picker-up]');
            const down = row.querySelector('[data-column-picker-down]');
            if (up) {
                up.disabled = index === 0;
            }
            if (down) {
                down.disabled = index === rows.length - 1;
            }
        });
    }
}
