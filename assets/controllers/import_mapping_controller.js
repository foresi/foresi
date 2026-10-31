/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The mapping screen while the user changes it: names the required fields no
 * column is mapped to (above the columns and next to the button) and holds the
 * check back until there are none, counts the columns that are imported, and
 * shows only the options that apply to the chosen mode. The server checks the
 * same on submit, so the form works without it.
 */
export default class extends Controller {
    static targets = ['missing', 'message', 'fields', 'submit', 'summary', 'writeOnly', 'replaceOnly'];
    static values = { required: Object, one: String, other: String, summary: String };

    connect() {
        this.update();
    }

    update() {
        const values = Array.from(this.element.querySelectorAll('select[name^="columns["]'), (select) => select.value);
        const chosen = new Set(values);
        const missing = Object.entries(this.requiredValue).filter(([key]) => !chosen.has(key)).map(([, label]) => label);
        if (this.hasMissingTarget) {
            this.missingTarget.hidden = missing.length === 0;
            this.messageTarget.textContent = missing.length === 1 ? this.oneValue : this.otherValue;
            this.fieldsTarget.textContent = missing.join(', ');
        }
        if (this.hasSummaryTarget && this.summaryValue !== '') {
            const mapped = values.filter((value) => value !== '').length;
            this.summaryTarget.textContent = missing.length > 0
                ? `${missing.length === 1 ? this.oneValue : this.otherValue} ${missing.join(', ')}.`
                : this.summaryValue.replace('__mapped__', String(mapped));
        }
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = missing.length > 0;
        }

        const mode = this.element.querySelector('input[name="mode"]:checked');
        const value = mode === null ? '' : mode.value;
        this.writeOnlyTargets.forEach((element) => { element.hidden = value === 'add_missing'; });
        this.replaceOnlyTargets.forEach((element) => { element.hidden = value !== 'full_replace'; });
    }
}
