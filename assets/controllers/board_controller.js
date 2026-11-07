/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * The board: a card is dragged by its handle into a place of any column. The
 * drop is saved at once with the status and the card it lands before; the
 * counts of the columns follow and the result is announced. A refused drop
 * loads the board again, which tells why. The menu of each card does the same
 * without dragging, as plain forms.
 *
 *     <div data-controller="board" data-board-token-value="…" data-board-moved-value="… __status__ … __position__ … __count__">
 */
export default class extends Controller {
    static targets = ['list', 'status'];
    static values = { token: String, moved: String };

    connect() {
        this.sortables = this.listTargets.map((list) => Sortable.create(list, {
            group: 'board',
            handle: '[data-board-handle]',
            draggable: '[data-board-card]',
            animation: 100,
            ghostClass: 'board-ghost',
            chosenClass: 'board-chosen',
            dragClass: 'board-drag',
            // Pointer events instead of the native drag: the same behaviour and look
            // with a mouse, a finger and a pen, and the floating card takes the drag style.
            forceFallback: true,
            fallbackClass: 'board-drag',
            onMove: (event) => this.highlight(event.to),
            onEnd: (event) => this.drop(event),
        }));
    }

    disconnect() {
        this.sortables.forEach((sortable) => sortable.destroy());
        this.sortables = [];
    }

    // The column that takes the drop is tinted.
    highlight(list) {
        this.listTargets.forEach((other) => other.closest('[data-board-column]').toggleAttribute('data-drop', other === list));
    }

    async drop(event) {
        this.highlight(null);
        const card = event.item;
        if (event.from === event.to && event.oldDraggableIndex === event.newDraggableIndex) {
            return;
        }
        const list = event.to;
        const next = card.nextElementSibling;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('statusId', list.dataset.statusId);
        body.append('beforeTaskId', next ? next.dataset.taskId : '');
        let response = null;
        try {
            response = await fetch(card.dataset.moveUrl, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        } catch {
            response = null;
        }
        if (!response || !response.ok) {
            window.Turbo ? window.Turbo.visit(window.location.href, { action: 'replace' }) : window.location.reload();
            return;
        }
        this.listTargets.forEach((each) => {
            const all = Array.from(each.querySelectorAll('[data-board-card]'));
            each.closest('[data-board-column]').querySelector('[data-board-count]').textContent = all.length;
            // The menus offer what fits the new places: the other statuses, up unless first, down unless last.
            all.forEach((item, at) => {
                item.querySelectorAll('[data-move-status]').forEach((form) => { form.hidden = form.dataset.moveStatus === each.dataset.statusId; });
                item.querySelectorAll('[data-move="up"]').forEach((form) => { form.hidden = at === 0; });
                item.querySelectorAll('[data-move="down"]').forEach((form) => { form.hidden = at === all.length - 1; });
            });
        });
        const cards = Array.from(list.querySelectorAll('[data-board-card]'));
        this.statusTarget.textContent = this.movedValue
            .replace('__status__', list.dataset.statusName)
            .replace('__position__', cards.indexOf(card) + 1)
            .replace('__count__', cards.length);
    }
}
