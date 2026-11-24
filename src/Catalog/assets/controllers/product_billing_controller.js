/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The fields of a product's form that depend on how it is billed: the period
 * (every N units, the cycles) shows only for a recurring product, and the unit
 * of a time-based product is fixed to the hour, "h". Without JavaScript every
 * field shows and the server ignores the period of a one-off or time-based
 * product, and takes an empty unit of a time-based one as "h".
 *
 *     <div data-controller="product-billing">
 */
export default class extends Controller {
    static targets = ['kind', 'period', 'unit'];

    connect() {
        this.changed();
    }

    changed() {
        const kind = this.kindTarget.value;
        this.periodTarget.hidden = 'recurring' !== kind;

        if (!this.hasUnitTarget) {
            return;
        }
        if ('time_based' === kind) {
            this.unitTarget.value = 'h';
            this.unitTarget.readOnly = true;
        } else {
            if (this.unitTarget.readOnly && 'h' === this.unitTarget.value) {
                this.unitTarget.value = '';
            }
            this.unitTarget.readOnly = false;
        }
    }
}
