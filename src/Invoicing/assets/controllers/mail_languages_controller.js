/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The languages of a mail template as tabs (WAI-ARIA APG tabs, automatic
 * activation): the arrow keys, Home and End move between them and show their
 * panel. Without JavaScript the tab list stays hidden and every panel shows,
 * each under its own heading. The selected value names the language shown
 * first, the first one with a problem when the form came back.
 *
 *     <div data-controller="mail-languages" data-mail-languages-selected-value="cs">
 *         <div role="tablist" hidden data-mail-languages-target="list" data-action="keydown->mail-languages#key">
 *             <button role="tab" data-language="cs" data-mail-languages-target="tab" data-action="mail-languages#select">
 *         </div>
 *         <section data-language="cs" data-mail-languages-target="panel">
 *             <h3 data-mail-languages-target="heading">
 */
export default class extends Controller {
    static targets = ['list', 'tab', 'panel', 'heading'];
    static values = { selected: String };

    connect() {
        this.listTarget.hidden = false;
        this.headingTargets.forEach((heading) => heading.classList.add('sr-only'));
        this.panelTargets.forEach((panel) => {
            const tab = this.tabFor(panel.dataset.language);
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);
        });
        this.show(this.selectedValue || this.tabTargets[0].dataset.language);
    }

    select(event) {
        this.show(event.currentTarget.dataset.language);
    }

    key(event) {
        const tabs = this.tabTargets;
        const index = tabs.indexOf(event.target);
        if (index < 0) {
            return;
        }
        const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: tabs.length - 1 }[event.key];
        if (next === undefined) {
            return;
        }
        event.preventDefault();
        const tab = tabs[(next + tabs.length) % tabs.length];
        this.show(tab.dataset.language);
        tab.focus();
    }

    show(language) {
        this.tabTargets.forEach((tab) => {
            const selected = tab.dataset.language === language;
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            tab.tabIndex = selected ? 0 : -1;
        });
        this.panelTargets.forEach((panel) => {
            panel.hidden = panel.dataset.language !== language;
        });
    }

    tabFor(language) {
        return this.tabTargets.find((tab) => tab.dataset.language === language);
    }
}
