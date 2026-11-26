/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The new meeting's dialog: choosing a series ticks its default attendees in
 * place of the ones ticked, and shows its name as the title's placeholder, the
 * title the server gives a meeting of a series without one. Without JavaScript
 * the dialog opened from a series (?series=) comes with them ticked already.
 *
 *     <form data-controller="meeting-series" data-meeting-series-defaults-value="{…}">
 */
export default class extends Controller {
    static targets = ['series', 'attendees'];
    static values = { defaults: Object };

    chosen() {
        const series = this.defaultsValue[this.seriesTarget.value];
        const title = this.element.querySelector('input[name="title"]');
        if (title) {
            title.placeholder = series ? series.name : '';
        }
        if (!series || !this.hasAttendeesTarget) {
            return;
        }
        for (const box of this.attendeesTarget.querySelectorAll('input[type="checkbox"]')) {
            box.checked = series.attendees.includes(box.value);
        }
    }
}
