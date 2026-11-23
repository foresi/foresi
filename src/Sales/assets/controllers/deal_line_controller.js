/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The form of a deal's line: a line picked from the catalog copies the
 * product, so the typed fields hide while a product is chosen; the period
 * fields show only for a recurring line. Without JavaScript every field shows
 * and the server ignores what does not apply.
 *
 *     <div data-controller="deal-line">
 */
export default class extends Controller {
    static targets = ['product', 'typed', 'kind', 'period'];

    connect() {
        this.changed();
    }

    changed() {
        const fromProduct = this.hasProductTarget && '' !== this.productTarget.value;
        if (this.hasTypedTarget) {
            this.typedTarget.hidden = fromProduct;
        }
        if (this.hasPeriodTarget && this.hasKindTarget) {
            this.periodTarget.hidden = 'recurring' !== this.kindTarget.value;
        }
    }
}
