/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Menu button with a popup menu (WAI-ARIA menu button pattern). Arrow keys,
 * Home and End move between items, Escape closes and returns focus to the
 * button, a click elsewhere or Tab closes it.
 */
export default class extends Controller {
    static targets = ['button', 'menu'];

    // When true, the open menu is placed with fixed positioning next to the
    // button, so a scrolling container (the sidebar) cannot clip it.
    static values = { float: Boolean };

    connect() {
        this.onDocumentClick = (event) => {
            if (!this.element.contains(event.target)) {
                this.close(false);
            }
        };
        this.onBeforeCache = () => this.close(false);
        document.addEventListener('click', this.onDocumentClick);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocumentClick);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
    }

    items() {
        // An item a breakpoint hides takes no focus.
        return Array.from(this.menuTarget.querySelectorAll('[role^="menuitem"]')).filter((item) => item.getClientRects().length > 0);
    }

    isOpen() {
        return !this.menuTarget.hidden;
    }

    toggle() {
        this.isOpen() ? this.close() : this.open();
    }

    open(focus = 'first') {
        this.menuTarget.hidden = false;
        if (this.floatValue) {
            this.place();
        }
        this.buttonTarget.setAttribute('aria-expanded', 'true');
        const items = this.items();
        if (items.length > 0) {
            (focus === 'last' ? items[items.length - 1] : items[0]).focus();
        }
    }

    place() {
        const rect = this.buttonTarget.getBoundingClientRect();
        const style = this.menuTarget.style;
        style.position = 'fixed';
        style.right = 'auto';
        style.minWidth = `${rect.width}px`;
        style.marginBottom = '0';
        const box = this.menuTarget.getBoundingClientRect();
        style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - box.width - 8))}px`;
        // Above the button, unless the top of the window would cut it: the first row of a table.
        if (rect.top >= box.height + 16) {
            style.top = 'auto';
            style.bottom = `${window.innerHeight - rect.top + 8}px`;
        } else {
            style.top = `${rect.bottom + 8}px`;
            style.bottom = 'auto';
        }
    }

    close(restoreFocus = true) {
        if (!this.isOpen()) {
            return;
        }
        this.menuTarget.hidden = true;
        this.buttonTarget.setAttribute('aria-expanded', 'false');
        if (restoreFocus !== false) {
            this.buttonTarget.focus();
        }
    }

    // Opens from the keyboard on the button.
    openFromButton(event) {
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.open(event.key === 'ArrowUp' ? 'last' : 'first');
        }
    }

    navigate(event) {
        const items = this.items();
        const index = items.indexOf(document.activeElement);
        let next = null;
        if (event.key === 'ArrowDown') {
            next = items[(index + 1) % items.length];
        } else if (event.key === 'ArrowUp') {
            next = items[(index - 1 + items.length) % items.length];
        } else if (event.key === 'Home') {
            next = items[0];
        } else if (event.key === 'End') {
            next = items[items.length - 1];
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.close();
            return;
        } else if (event.key === 'Tab') {
            this.close(false);
            return;
        }
        if (next) {
            event.preventDefault();
            next.focus();
        }
    }
}
