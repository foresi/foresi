/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const PREVIEW_DELAY = 300;

/**
 * The schedule of a recurring template's form. The lines, the customer and the
 * totals are the invoice form's controller's; this one only shows the fields the
 * schedule needs, lets a picked customer set the due days until they are typed,
 * and posts the form to the preview frame, 300 ms after the last change. The
 * server reads the whole form for the preview and writes nothing; without
 * JavaScript the Apply button re-renders the form with a fresh preview.
 */
export default class extends Controller {
    static targets = ['frame', 'unit', 'lastDay', 'end', 'endOn', 'endAfter', 'dueDays', 'dueDaysSet'];

    static values = { previewUrl: String };

    connect() {
        this.unitChanged();
        this.endChanged();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    unitChanged() {
        this.lastDayTarget.hidden = !['month', 'year'].includes(this.unitTarget.value);
    }

    endChanged() {
        this.endOnTarget.hidden = this.endTarget.value !== 'on_date';
        this.endAfterTarget.hidden = this.endTarget.value !== 'after_count';
    }

    customerPicked(event) {
        if (this.dueDaysSetTarget.value !== '1' && event.detail.dueDays !== undefined) {
            this.dueDaysTarget.value = event.detail.dueDays;
            this.changed();
        }
    }

    dueDaysTyped() {
        this.dueDaysSetTarget.value = '1';
    }

    changed() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), PREVIEW_DELAY);
    }

    async refresh() {
        this.request?.abort();
        this.request = new AbortController();
        try {
            const response = await fetch(this.previewUrlValue, {
                method: 'POST',
                body: new FormData(this.element),
                headers: { Accept: 'text/html', 'Turbo-Frame': this.frameTarget.id },
                signal: this.request.signal,
            });
            // A schedule that cannot be read answers 422 with what is wrong: that is shown too.
            if (response.ok || response.status === 422) {
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const frame = page.getElementById(this.frameTarget.id);
                if (frame !== null) {
                    this.frameTarget.replaceChildren(...frame.childNodes);
                }
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                throw error;
            }
        }
    }
}
