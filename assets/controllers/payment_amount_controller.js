/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const SPACES = /[\s   ]/g;

/**
 * The amount of the pay dialog, compared with what is still to pay as it is typed.
 *
 * - A difference of a few coins either way, within the limit of the issuer's
 *   country, offers a box to close the invoice by writing it off. The difference
 *   is never typed; the server works it out again and so does RecordPayment.
 * - Above what is still to pay, and not closed that way, a line says by how much
 *   the invoice will be overpaid, as iDoklad calls it. It only warns: the payment
 *   records all the same.
 *
 * Whole minor units throughout, never a float for the comparison.
 *
 *     <form data-controller="payment-amount" data-payment-amount-remaining-value="71000"
 *           data-payment-amount-unit-value="Kč" data-payment-amount-limit-value="99">
 */
export default class extends Controller {
    static targets = ['amount', 'warning', 'warningText', 'settle', 'settleBox', 'settleText'];
    static values = { remaining: Number, unit: String, limit: { type: Number, default: -1 } };

    connect() {
        this.check();
    }

    check() {
        const minor = this.minor(this.amountTarget.value);
        const difference = minor === null ? 0 : this.remainingValue - minor;
        const closable = minor !== null && this.remainingValue > 0 && difference !== 0 && this.limitValue >= 0 && Math.abs(difference) <= this.limitValue;
        if (this.hasSettleTarget) {
            this.settleTarget.hidden = !closable;
            if (!closable) {
                this.settleBoxTarget.checked = false;
            } else {
                this.settleTextTarget.textContent = this.settleTarget.dataset.template.replace('{difference}', this.written(Math.abs(difference)));
            }
        }
        const closed = closable && this.hasSettleBoxTarget && this.settleBoxTarget.checked;
        const over = -difference;
        this.warningTarget.hidden = over <= 0 || closed;
        if (over > 0) {
            this.warningTextTarget.textContent = this.warningTarget.dataset.template.replace('{over}', this.written(over));
        }
    }

    // "1 210,50" or "1210.5" in minor units; null for what is not an amount.
    minor(typed) {
        const match = /^(\d{1,13})(?:[.,](\d{0,2}))?$/.exec(typed.replace(SPACES, ''));
        if (match === null) {
            return null;
        }
        return Number(match[1]) * 100 + Number((match[2] || '').padEnd(2, '0'));
    }

    written(minor) {
        const language = document.documentElement.lang;
        const whole = new Intl.NumberFormat(language).format(Math.floor(minor / 100));
        const decimal = new Intl.NumberFormat(language).formatToParts(1.5).find((part) => part.type === 'decimal')?.value ?? ',';
        return `${whole}${decimal}${String(minor % 100).padStart(2, '0')} ${this.unitValue}`;
    }
}
