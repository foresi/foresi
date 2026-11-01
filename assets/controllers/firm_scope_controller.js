/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * A panel of the contact form whose data can apply to all firms or to one.
 * Every scope is a pane in the page, so switching takes no request and all of
 * them post with the form. A firm without its own values shows the shared ones
 * in a disabled fieldset; "set" gives it a copy to edit and "reset" takes it
 * back. Nothing is stored before the form is saved.
 */
export default class extends Controller {
    static targets = ['pane', 'option', 'marker', 'current', 'label', 'currentMarker'];
    static values = { active: String, confirm: String };

    connect() {
        this.show(this.activeValue);
    }

    choose(event) {
        this.show(event.currentTarget.dataset.scope);
    }

    set(event) {
        const pane = this.pane(event);
        this.copyShared(pane);
        this.markOwn(pane, true);
        this.part(pane, 'fields').querySelector('input:not([type="hidden"]), select, textarea')?.focus();
    }

    reset(event) {
        if (!window.confirm(this.confirmValue)) {
            return;
        }
        const pane = this.pane(event);
        this.markOwn(pane, false);
        this.copyShared(pane);
        this.part(pane, 'set').querySelector('button')?.focus();
    }

    show(scope) {
        this.activeValue = scope;
        this.currentTarget.value = scope;
        this.paneTargets.forEach((pane) => {
            const active = pane.dataset.scope === scope;
            pane.hidden = !active;
            if (active && this.isReadOnly(pane)) {
                // The shared values may have changed since this pane was last shown.
                this.copyShared(pane);
            }
        });
        this.optionTargets.forEach((option) => {
            const active = option.dataset.scope === scope;
            option.setAttribute('aria-checked', active ? 'true' : 'false');
            if (active) {
                this.labelTarget.textContent = option.dataset.name;
            }
        });
        this.showCurrentMarker();
    }

    markOwn(pane, own) {
        this.part(pane, 'own').value = own ? '1' : '';
        this.part(pane, 'fields').disabled = !own;
        this.part(pane, 'set').hidden = own;
        this.part(pane, 'reset').hidden = !own;
        this.markerTargets
            .filter((marker) => marker.dataset.scope === pane.dataset.scope)
            .forEach((marker) => { marker.hidden = !own; });
        this.showCurrentMarker();
    }

    showCurrentMarker() {
        const pane = this.paneTargets.find((candidate) => candidate.dataset.scope === this.activeValue);
        this.currentMarkerTarget.hidden = this.part(pane, 'own')?.value !== '1';
    }

    isReadOnly(pane) {
        return this.part(pane, 'fields').disabled;
    }

    copyShared(pane) {
        const shared = this.paneTargets.find((candidate) => candidate.dataset.scope === 'shared');
        const fields = (root) => Array.from(root.querySelectorAll('input:not([type="hidden"]), select, textarea'));
        const source = fields(shared);
        fields(pane).forEach((field) => {
            const from = source.find((candidate) => this.key(candidate) === this.key(field));
            if (from === undefined) {
                return;
            }
            if (field.type === 'checkbox') {
                field.checked = from.checked;
            } else {
                field.value = from.value;
            }
        });
    }

    // The field's own name, the same in every scope; a checkbox of a group also needs its value.
    key(field) {
        const name = field.name.replace(/\[\]$/, '').match(/(\w+)\]?$/)[1];

        return field.name.endsWith('[]') ? `${name}:${field.value}` : name;
    }

    pane(event) {
        return event.currentTarget.closest('[data-firm-scope-target="pane"]');
    }

    part(pane, role) {
        return pane.querySelector(`[data-firm-scope-role="${role}"]`);
    }
}
