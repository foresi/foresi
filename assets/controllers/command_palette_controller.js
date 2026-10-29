/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const fold = (value) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

/**
 * Command palette: a modal combobox over a static list of server-rendered
 * commands. Opens with Ctrl/Cmd+K or from the search button.
 */
export default class extends Controller {
    static targets = ['opener', 'overlay', 'input', 'list', 'option', 'empty', 'close'];

    connect() {
        this.onShortcut = (event) => {
            if ((event.metaKey || event.ctrlKey) && !event.altKey && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.isOpen() ? this.close() : this.open();
            }
        };
        window.addEventListener('keydown', this.onShortcut);
    }

    disconnect() {
        window.removeEventListener('keydown', this.onShortcut);
    }

    isOpen() {
        return !this.overlayTarget.hidden;
    }

    open() {
        if (this.isOpen()) {
            return;
        }
        this.returnTo = document.activeElement instanceof HTMLElement ? document.activeElement : this.openerTarget;
        this.overlayTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.inputTarget.value = '';
        this.filter();
        this.inputTarget.focus();
    }

    close() {
        if (!this.isOpen()) {
            return;
        }
        this.overlayTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.setActive(null);
        const target = this.returnTo && this.returnTo.isConnected ? this.returnTo : this.openerTarget;
        target.focus();
        this.returnTo = null;
    }

    backdrop(event) {
        if (event.target === this.overlayTarget) {
            this.close();
        }
    }

    filter() {
        const query = fold(this.inputTarget.value.trim());
        let visible = 0;
        this.optionTargets.forEach((option) => {
            const haystack = fold(`${option.textContent} ${option.dataset.keywords || ''}`);
            const match = query === '' || haystack.includes(query);
            option.hidden = !match;
            visible += match ? 1 : 0;
        });
        this.listTarget.querySelectorAll('[data-group]').forEach((group) => {
            group.hidden = group.querySelector('[role="option"]:not([hidden])') === null;
        });
        this.emptyTarget.hidden = visible > 0;
        this.setActive(this.visibleOptions()[0] || null);
    }

    visibleOptions() {
        return this.optionTargets.filter((option) => !option.hidden);
    }

    setActive(option) {
        this.optionTargets.forEach((item) => item.setAttribute('aria-selected', item === option ? 'true' : 'false'));
        if (option) {
            this.inputTarget.setAttribute('aria-activedescendant', option.id);
            option.scrollIntoView({ block: 'nearest' });
        } else {
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }

    keydown(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
        } else if (event.key === 'Tab') {
            this.trap(event);
        } else if (event.target === this.inputTarget) {
            this.navigate(event);
        }
    }

    navigate(event) {
        const options = this.visibleOptions();
        const current = options.findIndex((option) => option.getAttribute('aria-selected') === 'true');
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            if (options.length === 0) {
                return;
            }
            const step = event.key === 'ArrowDown' ? 1 : -1;
            this.setActive(options[(current + step + options.length) % options.length]);
        } else if (event.key === 'Home' || event.key === 'End') {
            if (options.length > 0 && event.ctrlKey) {
                event.preventDefault();
                this.setActive(event.key === 'Home' ? options[0] : options[options.length - 1]);
            }
        } else if (event.key === 'Enter' && current >= 0) {
            event.preventDefault();
            this.go(options[current]);
        }
    }

    choose(event) {
        const option = event.currentTarget;
        if (option.hidden) {
            return;
        }
        this.go(option);
    }

    go(option) {
        const href = option.dataset.href;
        this.close();
        if (!href) {
            return;
        }
        if (window.Turbo && typeof window.Turbo.visit === 'function') {
            window.Turbo.visit(href);
        } else {
            window.location.assign(href);
        }
    }

    // Keeps Tab inside the dialog: the input and the close button are its only tab stops.
    trap(event) {
        const stops = [this.inputTarget, ...(this.hasCloseTarget ? [this.closeTarget] : [])];
        const index = stops.indexOf(document.activeElement);
        const next = event.shiftKey ? index - 1 : index + 1;
        event.preventDefault();
        stops[(next + stops.length) % stops.length].focus();
    }
}
