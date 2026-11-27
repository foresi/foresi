/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * The rows of a table put in order by hand (Ui:DataTable reorder): a row is dragged by its
 * grip to another place, as the board's cards. The drop is saved at once with the row it
 * lands before, then the page is read again, so the rows' Move up and Move down fit the
 * new order; a refused drop reads it again too, and the page says why.
 *
 *     <tbody data-controller="row-reorder" data-row-reorder-url-value="/tasks/__id__/star/move" data-row-reorder-token-value="…">
 */
export default class extends Controller {
    static values = { url: String, token: String };

    connect() {
        this.sortable = Sortable.create(this.element, {
            handle: '[data-row-reorder-handle]',
            draggable: 'tr',
            animation: 100,
            ghostClass: 'row-ghost',
            chosenClass: 'row-chosen',
            dragClass: 'row-drag',
            // Pointer events instead of the native drag: the same with a mouse, a finger and a pen.
            forceFallback: true,
            fallbackClass: 'row-drag',
            onEnd: (event) => this.drop(event),
        });
    }

    disconnect() {
        this.sortable?.destroy();
        this.sortable = null;
    }

    async drop(event) {
        if (event.oldDraggableIndex === event.newDraggableIndex) {
            return;
        }
        const row = event.item;
        const next = row.nextElementSibling;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('beforeTaskId', next ? next.dataset.id : '');
        try {
            await fetch(this.urlValue.replace('__id__', encodeURIComponent(row.dataset.id)), { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        } finally {
            window.Turbo ? window.Turbo.visit(window.location.href, { action: 'replace' }) : window.location.reload();
        }
    }
}
