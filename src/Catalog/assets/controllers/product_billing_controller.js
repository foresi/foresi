/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The period fields of a product's form: every N units and the cycles show
 * only for a recurring product. Without JavaScript every field shows and the
 * server ignores the period of a one-off product.
 *
 *     <div data-controller="product-billing">
 */
export default class extends Controller {
    static targets = ['kind', 'period'];

    connect() {
        this.changed();
    }

    changed() {
        this.periodTarget.hidden = 'recurring' !== this.kindTarget.value;
    }
}
