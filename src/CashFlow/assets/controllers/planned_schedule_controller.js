/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The repeat fields of a planned item's form: every N, the last day of the
 * month and the end show only for an item that repeats, the last day only for
 * a monthly or yearly one, and the end day or count only for the end chosen.
 * Without JavaScript every field shows and the server ignores what does not
 * apply.
 *
 *     <div data-controller="planned-schedule">
 */
export default class extends Controller {
    static targets = ['repeat', 'recurring', 'lastDay', 'end', 'endsOn', 'endsAfter'];

    connect() {
        this.changed();
    }

    changed() {
        const unit = this.repeatTarget.value;
        const end = this.endTarget.value;
        this.recurringTarget.hidden = '' === unit;
        this.lastDayTarget.hidden = !['month', 'year'].includes(unit);
        this.endsOnTarget.hidden = 'on_date' !== end;
        this.endsAfterTarget.hidden = 'after_count' !== end;
    }
}
