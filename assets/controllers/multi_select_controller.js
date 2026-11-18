/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Ui:MultiSelect: an editable combobox over a multi-select listbox, after the
 * WAI-ARIA APG combobox and listbox patterns. Focus stays in the search
 * field; the active row is its aria-activedescendant. Typing filters the rows;
 * Down and Up move the active row; Enter ticks or unticks it (the popup stays
 * open, as Primer SelectPanel's does) or, on the create row, creates. Escape,
 * Tab and a click outside close the popup (dropdown_controller), and closing
 * posts the ticks once if they changed (Primer SelectPanel: closing applies).
 *
 * The page refreshes by morphing after any other change on it; while the
 * popup is open that morph leaves this form as it is, so the ticks are not
 * lost (as comment_form_controller).
 */
export default class extends Controller {
    static targets = ['search', 'option', 'input', 'create', 'createRow', 'createText', 'empty'];
    static values = { createLabel: String };

    connect() {
        this.shown = false;
        this.sending = false;
        this.guard = (event) => {
            if (event.target === this.element && this.shown && !this.sending) {
                event.preventDefault();
            }
        };
        document.addEventListener('turbo:before-morph-element', this.guard);
    }

    disconnect() {
        document.removeEventListener('turbo:before-morph-element', this.guard);
    }

    opened() {
        this.shown = true;
        this.sending = false;
        this.before = this.ticked();
        this.searchTarget.value = '';
        this.filter();
    }

    closed() {
        this.shown = false;
        if (this.sending || this.ticked() === this.before) {
            return;
        }
        this.send();
    }

    filter() {
        const typed = this.searchTarget.value.trim();
        const query = typed.toLocaleLowerCase();
        let named = false;
        this.optionTargets.forEach((option) => {
            const label = option.dataset.label.toLocaleLowerCase();
            option.hidden = query !== '' && !label.includes(query);
            named = named || label === query;
        });
        const creating = this.hasCreateRowTarget && typed !== '' && !named;
        if (this.hasCreateRowTarget) {
            this.createRowTarget.hidden = !creating;
            this.createTextTarget.textContent = this.createLabelValue.replace('{name}', typed);
        }
        const rows = this.rows();
        this.emptyTarget.firstElementChild.hidden = rows.length > 0;
        this.activate(rows[0] ?? null);
    }

    key(event) {
        const rows = this.rows();
        const index = rows.indexOf(this.active);
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (rows.length > 0) {
                const step = event.key === 'ArrowDown' ? 1 : -1;
                this.activate(rows[(index + step + rows.length) % rows.length]);
            }
        } else if (event.key === 'Enter') {
            // Enter never submits the form from the search field.
            event.preventDefault();
            if (this.active) {
                this.choose(this.active);
            }
        }
    }

    // A click on a row keeps the focus in the search field.
    keep(event) {
        event.preventDefault();
    }

    pick(event) {
        this.choose(event.currentTarget);
    }

    choose(row) {
        if (this.hasCreateRowTarget && row === this.createRowTarget) {
            this.createTarget.value = this.searchTarget.value.trim();
            this.send();

            return;
        }
        const selected = row.getAttribute('aria-selected') !== 'true';
        row.setAttribute('aria-selected', selected ? 'true' : 'false');
        const input = this.inputTargets.find((field) => field.value === row.dataset.value);
        if (input) {
            input.checked = selected;
        }
        this.activate(row);
        this.searchTarget.focus();
    }

    activate(row) {
        if (this.active) {
            delete this.active.dataset.active;
        }
        this.active = row;
        if (row) {
            row.dataset.active = '';
            this.searchTarget.setAttribute('aria-activedescendant', row.id);
            row.scrollIntoView({ block: 'nearest' });
        } else {
            this.searchTarget.removeAttribute('aria-activedescendant');
        }
    }

    rows() {
        const rows = this.optionTargets.filter((option) => !option.hidden);
        if (this.hasCreateRowTarget && !this.createRowTarget.hidden) {
            rows.push(this.createRowTarget);
        }

        return rows;
    }

    ticked() {
        return this.inputTargets.filter((input) => input.checked).map((input) => input.value).join(' ');
    }

    send() {
        this.sending = true;
        this.element.requestSubmit();
    }
}
