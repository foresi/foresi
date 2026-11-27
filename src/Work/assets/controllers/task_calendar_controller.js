/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * The calendar of due dates (plan 37.5): a task is dragged by its handle onto another day,
 * which sets its due date, or onto "No due date", which clears it. The drop posts the task's
 * own due date edit and the page comes back in place (Turbo morph), so what the server made of
 * it, or why it refused, is what shows. Without dragging, the date changes in the task's sheet.
 *
 *     <div data-controller="task-calendar" data-task-calendar-url-value="/tasks/__id__/due" data-task-calendar-token-value="…" data-task-calendar-return-value="/tasks?…">
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { url: String, token: String, return: String };

    connect() {
        this.sortables = this.listTargets.map((list) => Sortable.create(list, {
            group: 'task-calendar',
            handle: '[data-calendar-handle]',
            draggable: '[data-calendar-task]',
            animation: 100,
            ghostClass: 'calendar-ghost',
            chosenClass: 'calendar-chosen',
            dragClass: 'calendar-drag',
            // Pointer events instead of the native drag, as the board: the same with a mouse, a finger and a pen.
            forceFallback: true,
            fallbackClass: 'calendar-drag',
            onEnd: (event) => this.drop(event),
        }));
    }

    disconnect() {
        this.sortables.forEach((sortable) => sortable.destroy());
        this.sortables = [];
    }

    async drop(event) {
        if (event.from === event.to) {
            // The order inside a day is the list's (priority), not the person's.
            return;
        }
        const day = event.to.dataset.day;
        const body = new FormData();
        body.append('_token', this.tokenValue);
        body.append('return', this.returnValue);
        if (day === '') {
            body.append('clear', '1');
        } else {
            body.append('due', day);
        }
        try {
            await fetch(this.urlValue.replace('__id__', event.item.dataset.taskId), { method: 'POST', body, credentials: 'same-origin', redirect: 'manual' });
        } finally {
            window.Turbo ? window.Turbo.visit(window.location.href, { action: 'replace' }) : window.location.reload();
        }
    }
}
