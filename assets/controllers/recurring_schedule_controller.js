/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const PREVIEW_DELAY = 300;

/**
 * The schedule and the sending of a recurring template's form. The lines, the
 * customer and the totals are the invoice form's controller's; this one only
 * shows the fields the schedule needs, steps the numbers with − and +, lets a
 * picked customer set the due days until they are typed, says when an unticked
 * "Odběrateli" leaves the invoice waiting, disables a box with no address to
 * send to, and posts the form for the preview,
 * 300 ms after the last change. The answer holds the next issue and the other
 * parts that follow what was typed ([data-recurring-live], such as the addresses
 * under the boxes); each takes the place of the one with its id. The server
 * reads the whole form and writes nothing; without JavaScript the Apply button
 * re-renders the form with all of them fresh.
 *
 * The preview is one line with the next issue and a link to the dates after
 * it. The link is the summary of a disclosure that holds them; here it opens
 * them in the card's dialog instead, which a refresh keeps up to date.
 */
export default class extends Controller {
    static targets = ['frame', 'unit', 'lastDay', 'end', 'endOn', 'endAfter', 'dueDays', 'dueDaysSet', 'list', 'dialogList', 'stepper', 'box', 'sendToCustomer', 'held'];

    static outlets = ['dialog'];

    static values = { previewUrl: String };

    connect() {
        this.unitChanged();
        this.endChanged();
        this.recipientsChanged();
        this.stepperTargets.forEach((button) => { button.hidden = false; });
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

    sendingChanged() {
        this.heldTarget.hidden = this.sendToCustomerTarget.checked;
    }

    // A box with no address is unticked and disabled; what was chosen for it waits in
    // {box}Wanted, which is posted instead, and comes back once it has an address.
    recipientsChanged() {
        this.boxTargets.forEach((box) => {
            const empty = document.getElementById(`${box.id}-address`)?.hasAttribute('data-recurring-empty') ?? false;
            const wanted = document.getElementById(`${box.id}-wanted`);
            if (empty && !box.disabled) {
                wanted.value = box.checked ? '1' : '';
                box.checked = false;
            } else if (!empty && box.disabled) {
                box.checked = wanted.value === '1';
            }
            box.disabled = empty;
            wanted.disabled = !empty;
        });
        this.sendingChanged();
    }

    // − and +: one step within the field's bounds, as if typed.
    step({ params: { field, delta } }) {
        const input = this.element.querySelector(`#${CSS.escape(field)}`);
        const min = Number(input.dataset.min);
        const max = Number(input.dataset.max);
        const typed = Number.parseInt(input.value, 10);
        input.value = String(Math.min(max, Math.max(min, (Number.isNaN(typed) ? min : typed) + delta)));
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // A picked customer has addresses of its own, and may set the due days.
    customerPicked(event) {
        if (this.dueDaysSetTarget.value !== '1' && event.detail.dueDays !== undefined) {
            this.dueDaysTarget.value = event.detail.dueDays;
        }
        this.changed();
    }

    dueDaysTyped() {
        this.dueDaysSetTarget.value = '1';
    }

    changed() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), PREVIEW_DELAY);
    }

    showAll(event) {
        if (!this.hasDialogOutlet || !this.hasListTarget) {
            return;
        }
        event.preventDefault();
        this.fillDialog();
        this.dialogOutlet.open(event.currentTarget);
    }

    fillDialog() {
        const nodes = this.hasListTarget ? Array.from(this.listTarget.childNodes, (node) => node.cloneNode(true)) : [];
        this.dialogListTarget.replaceChildren(...nodes);
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
                    if (this.hasDialogOutlet && this.dialogOutlet.element.open) {
                        this.hasListTarget ? this.fillDialog() : this.dialogOutlet.close();
                    }
                }
                page.querySelectorAll('[data-recurring-live][id]').forEach((part) => {
                    document.getElementById(part.id)?.replaceWith(document.importNode(part, true));
                });
                // Another customer has other addresses.
                this.recipientsChanged();
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                throw error;
            }
        }
    }
}
