/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The time fields of an activity form follow the chosen type: a start time and
 * a duration for a timed type (a meeting, a call), a day for a dated one (a
 * task), none for a note. The fields of the other timings are hidden and
 * disabled, so they are not sent. Without JavaScript every field shows and
 * the type decides on the server.
 *
 *     <form data-controller="activity-timing" data-activity-timing-timings-value='{"<type id>": "timed"}'>
 *         <select data-activity-timing-target="type" data-action="activity-timing#show">...</select>
 *         <div data-activity-timing-target="group" data-timing="timed">...</div>
 */
export default class extends Controller {
    static targets = ['type', 'group'];
    static values = { timings: Object };

    connect() {
        this.show();
    }

    show() {
        if (!this.hasTypeTarget) {
            return;
        }
        const timing = this.timingsValue[this.typeTarget.value] ?? null;
        for (const group of this.groupTargets) {
            const shown = timing === null || group.dataset.timing.split(' ').includes(timing);
            group.hidden = !shown;
            for (const field of group.querySelectorAll('input, select, textarea')) {
                field.disabled = !shown;
            }
        }
    }
}
