/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The box "Bill time" of a new project follows its client: picking a client
 * ticks it and clearing the client unticks it, as Harvest starts a client's
 * project billable, until the person ticks or unticks it by hand; from then on
 * the box keeps what they chose. Work's new project dialog renders this through
 * ProjectSlots::NEW_FORM with the id of its client combobox, which sends
 * "combobox:pick" and "combobox:clear". Without JavaScript the box stays as the
 * server rendered it.
 *
 *     <div hidden data-controller="billable-default" data-billable-default-client-value="client"
 *          data-billable-default-field-value="timesheet_billable_by_default"></div>
 */
export default class extends Controller {
    static values = { client: String, field: String };

    connect() {
        this.form = this.element.closest('form');
        this.box = this.form?.querySelector(`[data-custom-field="${CSS.escape(this.fieldValue)}"] input[type="checkbox"]`);
        if (!this.box) {
            return;
        }
        this.byHand = false;
        // A change event comes from the person only; setting checked below sends none.
        this.changed = () => {
            this.byHand = true;
        };
        this.picked = (event) => this.follow(event, true);
        this.cleared = (event) => this.follow(event, false);
        this.box.addEventListener('change', this.changed);
        this.form.addEventListener('combobox:pick', this.picked);
        this.form.addEventListener('combobox:clear', this.cleared);
    }

    disconnect() {
        if (!this.box) {
            return;
        }
        this.box.removeEventListener('change', this.changed);
        this.form.removeEventListener('combobox:pick', this.picked);
        this.form.removeEventListener('combobox:clear', this.cleared);
    }

    follow(event, billable) {
        if (this.byHand || !(event.target instanceof Element) || !event.target.querySelector(`#${CSS.escape(this.clientValue)}`)) {
            return;
        }
        this.box.checked = billable;
    }
}
