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
    static targets = ['opener', 'overlay', 'dialog', 'input', 'list', 'option', 'empty', 'close', 'shortcut'];

    connect() {
        this.inerted = [];
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
        // While open, Tab and Escape are handled on the document so they work wherever focus is.
        this.onKeydown = (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
            } else if (event.key === 'Tab') {
                this.trap(event);
            }
        };
        this.onBeforeCache = () => this.close(false);
        window.addEventListener('keydown', this.onShortcut);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        if (this.hasShortcutTarget && this.isMac()) {
            this.shortcutTarget.textContent = '\u2318K';
        }
    }

    disconnect() {
        window.removeEventListener('keydown', this.onShortcut);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        document.removeEventListener('keydown', this.onKeydown, true);
        this.release();
    }

    isMac() {
        const platform = (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '';
        return /mac|iphone|ipad|ipod/i.test(platform);
    }

    // Makes everything outside the palette inert, so the page behind cannot be reached.
    isolate() {
        let node = this.element;
        while (node && node !== document.body) {
            const parent = node.parentElement;
            if (!parent) {
                break;
            }
            Array.from(parent.children).forEach((sibling) => {
                if (sibling !== node && !sibling.inert) {
                    sibling.inert = true;
                    this.inerted.push(sibling);
                }
            });
            node = parent;
        }
        // Inside the palette only the dialog stays reachable.
        Array.from(this.element.children).forEach((child) => {
            if (child !== this.overlayTarget && !child.inert) {
                child.inert = true;
                this.inerted.push(child);
            }
        });
    }

    release() {
        this.inerted.forEach((node) => {
            node.inert = false;
        });
        this.inerted = [];
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
        this.isolate();
        document.addEventListener('keydown', this.onKeydown, true);
        this.inputTarget.setAttribute('aria-expanded', 'true');
        this.inputTarget.value = '';
        this.filter();
        this.inputTarget.focus();
    }

    close(restoreFocus = true) {
        if (!this.isOpen()) {
            return;
        }
        document.removeEventListener('keydown', this.onKeydown, true);
        this.release();
        this.overlayTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.setActive(null);
        if (restoreFocus !== false) {
            const target = this.returnTo && this.returnTo.isConnected ? this.returnTo : this.openerTarget;
            target.focus();
        }
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

    go(option) {
        const href = option.dataset.href;
        this.close();
        if (!href) {
            return;
        }
        if (window.Turbo && typeof window.Turbo.visit === 'function' && document.body.dataset.turbo !== 'false') {
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
