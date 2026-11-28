/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const SPACES = /[\s   ]/g;

/**
 * The parts of the pair dialog, added up as they are ticked and typed.
 *
 * - Ticking an invoice fills in what it has left, up to what the movement still
 *   has to give, so a second invoice takes the rest of the first. Not for a
 *   payment the bank did not vouch for (fill false): the person types what they
 *   found in the bank.
 * - The running sum says what is split and what is left, or by how much the parts
 *   are more than the movement. The server checks the same and refuses otherwise.
 * - The box to write off a small difference shows only for one invoice taking the
 *   whole movement within the firm's limit; the server works the difference out.
 * - Enter in the search field searches; Enter in an amount pairs.
 *
 * Whole minor units throughout, never a float for the comparison.
 *
 *     <form data-controller="pair-sum" data-pair-sum-total-value="121000"
 *           data-pair-sum-unit-value="Kč" data-pair-sum-limit-value="99"
 *           data-pair-sum-minor-units-value="2">
 */
export default class extends Controller {
    static targets = ['line', 'check', 'amount', 'sum', 'left', 'settle', 'search'];
    static values = { total: Number, unit: String, limit: { type: Number, default: -1 }, fill: { type: Boolean, default: true }, minorUnits: { type: Number, default: 2 } };

    connect() {
        this.check();
    }

    ticked(event) {
        const line = event.target.closest('[data-pair-invoice]');
        const field = line.querySelector('[data-pair-sum-target="amount"]');
        if (event.target.checked && this.fillValue) {
            const others = this.checkedLines().filter((other) => other !== line).reduce((sum, other) => sum + (this.minor(this.amountOf(other).value) ?? 0), 0);
            const rest = this.totalValue - others;
            if (rest > 0) {
                field.value = this.written(Math.min(Number(line.dataset.remaining), rest), false);
            }
        }
        this.check();
    }

    check() {
        const lines = this.checkedLines();
        const sum = lines.reduce((total, line) => total + (this.minor(this.amountOf(line).value) ?? 0), 0);
        if (this.hasSumTarget) {
            this.sumTarget.textContent = this.written(sum, true);
        }
        if (this.hasLeftTarget) {
            const difference = this.totalValue - sum;
            this.leftTarget.hidden = difference === 0;
            const template = difference > 0 ? this.leftTarget.dataset.leftTemplate : this.leftTarget.dataset.overTemplate;
            // Nothing to say when it adds up, so a screen reader hears nothing either.
            this.leftTarget.textContent = difference === 0 ? '' : template.replace('{amount}', this.written(Math.abs(difference), true));
        }
        if (this.hasSettleTarget) {
            const one = lines.length === 1 ? lines[0] : null;
            const remaining = one ? Number(one.dataset.remaining) : 0;
            const closable = one !== null && this.minor(this.amountOf(one).value) === this.totalValue && remaining !== this.totalValue && this.limitValue >= 0 && Math.abs(remaining - this.totalValue) <= this.limitValue;
            this.settleTarget.hidden = !closable;
            const box = this.settleTarget.querySelector('input[type="checkbox"]');
            if (!closable && box) {
                box.checked = false;
            }
        }
    }

    search(event) {
        event.preventDefault();
        this.element.requestSubmit(this.searchTarget);
    }

    submit(event) {
        event.preventDefault();
        this.element.requestSubmit();
    }

    checkedLines() {
        return this.lineTargets.filter((line) => line.querySelector('[data-pair-sum-target="check"]').checked);
    }

    amountOf(line) {
        return line.querySelector('[data-pair-sum-target="amount"]');
    }

    // "1 210,50" or "1210.5" in minor units; null for what is not an amount.
    minor(typed) {
        const group = new Intl.NumberFormat(document.documentElement.lang).formatToParts(1234.5).find((part) => part.type === 'group')?.value ?? ' ';
        const text = typed.replace(SPACES, '');
        const match = this.pattern().exec(group === ',' ? text.replaceAll(',', '') : text);
        if (match === null) {
            return null;
        }
        return Number(match[1]) * this.factor() + Number((match[2] || '').padEnd(this.minorUnitsValue, '0'));
    }

    written(minor, unit) {
        const number = this.number(minor);
        return unit ? `${number} ${this.unitValue}` : number;
    }

    // One minor unit's worth of the currency: 100 for two decimals, 1 for none, 1000 for three.
    factor() {
        return 10 ** this.minorUnitsValue;
    }

    // Whole units, then as many decimals as the currency has and none when it has none.
    pattern() {
        return new RegExp(`^(\\d{1,13})(?:[.,](\\d{0,${this.minorUnitsValue}}))?$`);
    }

    // "1 210,50": the grouped whole units and the decimals of the currency.
    number(minor) {
        const language = document.documentElement.lang;
        const whole = new Intl.NumberFormat(language).format(Math.floor(minor / this.factor()));
        if (this.minorUnitsValue === 0) {
            return whole;
        }
        const decimal = new Intl.NumberFormat(language).formatToParts(1.5).find((part) => part.type === 'decimal')?.value ?? ',';
        return `${whole}${decimal}${String(minor % this.factor()).padStart(this.minorUnitsValue, '0')}`;
    }
}
