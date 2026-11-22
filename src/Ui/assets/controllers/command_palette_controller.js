/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const fold = (value) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

/**
 * Command palette: a combobox over a static list of server-rendered commands,
 * in the shared dialog, which makes the page inert, closes on Escape and on a
 * click outside, and returns the focus. Opens with Ctrl/Cmd+K or from the
 * search button.
 */
export default class extends Controller {
    static targets = ['opener', 'dialog', 'input', 'list', 'option', 'empty', 'close', 'shortcut'];
    static outlets = ['dialog'];

    connect() {
        this.onShortcut = (event) => {
            if (!(event.metaKey || event.ctrlKey) || event.altKey || event.shiftKey || event.repeat || event.key.toLowerCase() !== 'k') {
                return;
            }
            const target = event.target;
            const editable = target instanceof Element && target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])');
            if (editable && !this.element.contains(target)) {
                return;
            }
            event.preventDefault();
            this.isOpen() ? this.close() : this.open();
        };
        window.addEventListener('keydown', this.onShortcut);
        if (this.hasShortcutTarget && this.isMac()) {
            this.shortcutTarget.textContent = '\u2318K';
        }
    }

    disconnect() {
        window.removeEventListener('keydown', this.onShortcut);
    }

    isMac() {
        const platform = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
        return /mac|iphone|ipad|ipod/i.test(platform);
    }

    isOpen() {
        return this.dialogTarget.open;
    }

    open() {
        if (this.isOpen() || !this.hasDialogOutlet) {
            return;
        }
        this.inputTarget.value = '';
        this.filter();
        // Another dialog may be open: one at a time.
        if (!this.dialogOutlet.open(document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : this.openerTarget)) {
            return;
        }
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.inputTarget.focus();
    }

    close() {
        return this.hasDialogOutlet ? this.dialogOutlet.close() : Promise.resolve();
    }

    // However the dialog closed: Escape, the X, a click outside or a chosen command.
    closed() {
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.setActive(null);
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

    // Clicking inside the dialog on a non-focusable area must not drop focus to the page.
    keepFocus(event) {
        if (!event.target.closest('input, button, a, [tabindex]:not([tabindex="-1"])')) {
            event.preventDefault();
            this.inputTarget.focus();
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

    async go(option) {
        const href = option.dataset.href;
        // Closed first: on a phone the dialog's own history entry must be gone before the visit.
        await this.close();
        if (!href) {
            return;
        }
        if (window.Turbo && typeof window.Turbo.visit === 'function' && document.body.dataset.turbo !== 'false') {
            window.Turbo.visit(href);
        } else {
            window.location.assign(href);
        }
    }
}
