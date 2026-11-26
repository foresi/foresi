/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const STEP = 16;
const WIDE_STEP = 64;
// px-4 on either side of a cell.
const PADDING = 32;
const SAVE_AFTER_KEYS = 600;

/**
 * Ui:DataTable's column widths. Each header has a grip on its right edge, a separator (WAI-ARIA APG
 * Window Splitter): dragging it with a pointer (mouse, pen or touch), or the arrow keys (Shift for a
 * bigger step, Home for the narrowest), set the column's width; a double-click sizes it to its
 * widest text, as Excel and Google Sheets do. The first change gives every shown column the width
 * it has, so the others stay where they are and the table lays them out at those widths alone.
 * The widths go to the list's address as widths=key:px,… and the list loads in place: the server
 * remembers them for the person (RememberedDisplay), and Save to view keeps them for everyone. On a
 * page of several tables, each writes its own name (`param`), as reasons[widths] under a list slot
 * (ListSlot), so the others keep theirs.
 */
export default class extends Controller {
    static targets = ['table', 'col', 'grip'];
    static values = { max: { type: Number, default: 1200 }, reserve: { type: Number, default: 0 }, param: { type: String, default: 'widths' } };

    // A focusable separator says its value (WAI-ARIA): the width each column has as it is laid out.
    connect() {
        this.gripTargets.forEach((grip) => {
            const width = Math.round(grip.parentElement.getBoundingClientRect().width);
            if (width > 0) {
                grip.setAttribute('aria-valuenow', String(width));
            }
        });
    }

    disconnect() {
        window.clearTimeout(this.timer);
    }

    grab(event) {
        if (event.button !== 0) {
            return;
        }
        event.preventDefault();
        const grip = event.currentTarget;
        const th = grip.parentElement;
        const startX = event.clientX;
        const start = Math.round(th.getBoundingClientRect().width);
        let frozen = false;
        let moved = false;
        grip.setPointerCapture(event.pointerId);
        grip.setAttribute('data-resizing', '');
        const move = (moveEvent) => {
            const delta = moveEvent.clientX - startX;
            if (!moved && Math.abs(delta) < 2) {
                return;
            }
            moved = true;
            if (!frozen) {
                this.freeze();
                frozen = true;
            }
            this.apply(grip, start + delta);
        };
        const drop = () => {
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', drop);
            grip.removeEventListener('pointercancel', drop);
            grip.removeAttribute('data-resizing');
            if (moved) {
                this.save(grip);
            }
        };
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', drop);
        grip.addEventListener('pointercancel', drop);
    }

    key(event) {
        const grip = event.currentTarget;
        const width = Math.round(grip.parentElement.getBoundingClientRect().width);
        const step = event.shiftKey ? WIDE_STEP : STEP;
        const next = {
            ArrowLeft: width - step,
            ArrowRight: width + step,
            Home: this.min(grip),
        }[event.key];
        if (next === undefined) {
            return;
        }
        event.preventDefault();
        this.freeze();
        this.apply(grip, next);
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.save(grip), SAVE_AFTER_KEYS);
    }

    fit(event) {
        event.preventDefault();
        const grip = event.currentTarget;
        this.freeze();
        this.apply(grip, this.textWidth(grip));
        this.save(grip);
    }

    // Every shown column keeps the width it has now; a column hidden at this size keeps its own.
    freeze() {
        if (this.frozen) {
            return;
        }
        this.frozen = true;
        this.gripTargets.forEach((grip) => {
            const width = Math.round(grip.parentElement.getBoundingClientRect().width);
            if (width > 0) {
                this.set(grip.dataset.key, width);
            }
        });
        this.tableTarget.classList.add('sm:table-fixed', 'sm:w-[max(100%,var(--table-w))]');
        this.sum();
    }

    apply(grip, width) {
        const clamped = Math.round(Math.min(Math.max(width, this.min(grip)), this.maxValue));
        this.set(grip.dataset.key, clamped);
        grip.setAttribute('aria-valuenow', String(clamped));
        this.sum();
    }

    set(key, width) {
        const col = this.col(key);
        if (!col) {
            return;
        }
        col.style.setProperty('--col-w', `${width}px`);
        col.classList.add('sm:w-(--col-w)');
    }

    // The table is as wide as its columns and the room the checkbox and actions need, or its frame.
    sum() {
        const total = this.colTargets.reduce((sum, col) => sum + (this.widthOf(col) ?? 0), 0);
        this.tableTarget.style.setProperty('--table-w', `${total + this.reserveValue}px`);
    }

    // The widest text of the column, its header's among them, with the cell's padding.
    textWidth(grip) {
        const th = grip.parentElement;
        const index = Array.prototype.indexOf.call(th.parentElement.children, th);
        let widest = this.contentWidth(th);
        this.tableTarget.querySelectorAll('tbody tr, tfoot tr').forEach((row) => {
            const cell = row.children[index];
            if (cell) {
                widest = Math.max(widest, this.contentWidth(cell));
            }
        });

        return Math.ceil(widest) + PADDING;
    }

    /*
     * How wide a cell's content is with nothing cut: the text laid out in full even where an
     * ellipsis hides its end, and the inline boxes (badges, chips, icons), from the cell's start
     * (its end for a right aligned one). Block boxes are as wide as the cell, so only what is in
     * them counts; what is hidden or set apart (a screen reader's text, the grip) does not.
     */
    contentWidth(cell) {
        const style = window.getComputedStyle(cell);
        const box = cell.getBoundingClientRect();
        const start = box.left + Number.parseFloat(style.paddingLeft);
        const end = box.right - Number.parseFloat(style.paddingRight);
        let left = Infinity;
        let right = -Infinity;
        const take = (rect) => {
            if (rect.width > 0) {
                left = Math.min(left, rect.left);
                right = Math.max(right, rect.right);
            }
        };
        const range = document.createRange();
        const walker = document.createTreeWalker(cell, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    return node.textContent.trim() === '' ? NodeFilter.FILTER_SKIP : NodeFilter.FILTER_ACCEPT;
                }
                const display = window.getComputedStyle(node);
                if (display.display === 'none' || display.position === 'absolute' || display.position === 'fixed') {
                    return NodeFilter.FILTER_REJECT;
                }
                if (display.display.startsWith('inline-') || node instanceof SVGElement) {
                    take(node.getBoundingClientRect());
                    return NodeFilter.FILTER_REJECT;
                }
                return NodeFilter.FILTER_SKIP;
            },
        });
        for (let node = walker.nextNode(); node; node = walker.nextNode()) {
            range.selectNodeContents(node);
            take(range.getBoundingClientRect());
        }
        if (left === Infinity) {
            return 0;
        }
        if (style.textAlign === 'right' || style.textAlign === 'end') {
            return end - left;
        }
        if (style.textAlign === 'center') {
            return right - left;
        }

        return right - start;
    }

    save(grip) {
        window.clearTimeout(this.timer);
        const widths = this.colTargets
            .map((col) => [col.dataset.column, this.widthOf(col)])
            .filter(([, width]) => width !== null)
            .map(([key, width]) => `${key}:${width}`)
            .join(',');
        const url = new URL(window.location.href);
        url.searchParams.set(this.paramValue, widths);
        const scroller = this.tableTarget.parentElement;
        const main = document.getElementById('main');
        const scope = `[data-column-resize-param-value="${CSS.escape(this.paramValue)}"]`;
        const kept = { x: scroller.scrollLeft, y: window.scrollY, main: main ? main.scrollTop : 0, key: grip.dataset.key, focused: document.activeElement === grip };
        // The list comes back where it was, the grip still focused for the next key.
        document.addEventListener('turbo:load', () => {
            const table = document.querySelector(`${scope} [data-column-resize-target="table"]`);
            if (table) {
                table.parentElement.scrollLeft = kept.x;
            }
            window.scrollTo(0, kept.y);
            const next = document.getElementById('main');
            if (next) {
                next.scrollTop = kept.main;
            }
            if (kept.focused) {
                document.querySelector(`${scope} [data-column-resize-target="grip"][data-key="${CSS.escape(kept.key)}"]`)?.focus();
            }
        }, { once: true });
        if (window.Turbo && typeof window.Turbo.visit === 'function') {
            window.Turbo.visit(url.toString(), { action: 'replace' });
        } else {
            window.location.replace(url.toString());
        }
    }

    min(grip) {
        return Number.parseInt(grip.dataset.min || '64', 10);
    }

    col(key) {
        return this.colTargets.find((col) => col.dataset.column === key);
    }

    widthOf(col) {
        const width = Number.parseInt(col.style.getPropertyValue('--col-w'), 10);

        return Number.isNaN(width) ? null : width;
    }
}
