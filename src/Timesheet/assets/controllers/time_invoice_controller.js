/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const STEPS = { none: 1, 15: 15, 30: 30 };

/**
 * The totals of the time ticked in the dialog that invoices it, as its lines
 * will bill it: each entry rounded up first, the entries grouped as chosen and
 * split by rate, each line's hours rounded to a hundredth and priced at its
 * rate. The server works out the same lines on Continue and refuses ticked
 * time without a rate, or rates in more than one currency.
 *
 * Each entry says what it is in data attributes: its minutes, its rate in
 * minor units, its currency (empty without a rate) and the minor units in a
 * unit of that currency (data-scale, 100 for crowns), and its group under
 * every grouping (data-group-project, -task, -person, -entry).
 *
 * Whole minor units and hundredths of an hour throughout, never a float sum.
 */
export default class extends Controller {
    static targets = ['tick', 'grouping', 'rounding', 'hours', 'amount', 'noRate'];
    static values = { locale: String, hours: String };

    connect() {
        this.count();
    }

    count() {
        if (!this.hasHoursTarget) {
            return;
        }
        const step = STEPS[this.hasRoundingTarget ? this.roundingTarget.value : 'none'] ?? 1;
        const grouping = this.hasGroupingTarget ? this.groupingTarget.value : 'task';
        const lines = new Map();
        let unpriced = 0;
        this.tickTargets.filter((tick) => tick.checked).forEach((tick) => {
            const entry = tick.closest('[data-time-invoice-entry]');
            const minutes = Math.ceil(Number(entry.dataset.minutes) / step) * step;
            if (entry.dataset.rate === '') {
                unpriced += minutes;
                return;
            }
            const key = `${entry.getAttribute(`data-group-${grouping}`)}|${entry.dataset.currency}|${entry.dataset.rate}`;
            const line = lines.get(key) ?? { minutes: 0, rate: Number(entry.dataset.rate), currency: entry.dataset.currency, scale: Number(entry.dataset.scale) };
            line.minutes += minutes;
            lines.set(key, line);
        });
        const hundredths = (minutes) => Math.floor((minutes * 100 + 30) / 60);
        let hours = hundredths(unpriced);
        let amount = 0;
        let scale = 1;
        const currencies = new Set();
        lines.forEach((line) => {
            const lineHours = hundredths(line.minutes);
            hours += lineHours;
            // The rate times the hours, half up to the minor unit, as a line of the invoice comes to.
            amount += Math.round((line.rate * lineHours) / 100);
            currencies.add(line.currency);
            ({ scale } = line);
        });
        const number = new Intl.NumberFormat(this.localeValue, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        this.hoursTarget.textContent = this.hoursValue.replace('{hours}', number.format(hours / 100));
        this.amountTarget.textContent = unpriced === 0 && currencies.size === 1
            ? new Intl.NumberFormat(this.localeValue, { style: 'currency', currency: [...currencies][0] }).format(amount / scale)
            : '—';
        if (this.hasNoRateTarget) {
            this.noRateTarget.hidden = unpriced === 0;
        }
    }
}
