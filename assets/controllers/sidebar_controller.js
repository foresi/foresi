/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The shell breakpoint, from the --breakpoint-shell token of assets/styles/app.css,
 * so the overlay and the layout switch at the same width.
 */
function narrowQuery() {
    const width = getComputedStyle(document.documentElement).getPropertyValue('--breakpoint-shell').trim();

    return `(width < ${width})`;
}

/**
 * Narrow screens: the sidebar is a full-screen overlay behind the menu button of the app bar.
 * While it is open the app bar and the page behind it are inert, the page does not scroll (the
 * html:has(#navigation[data-open]) rule of app.css), Escape and its X close it and focus returns
 * to the menu button. A visit to another page closes it before Turbo caches the page. On wide
 * screens the sidebar is always shown and this does nothing.
 */
export default class extends Controller {
    static targets = ['panel', 'toggle', 'page'];

    connect() {
        this.media = window.matchMedia(narrowQuery());
        this.onKeydown = (event) => {
            // A menu inside the overlay handles its own Escape first; an open dialog takes Escape for
            // itself, and preventing it here would keep the dialog open.
            if (event.key === 'Escape' && !event.defaultPrevented && this.isOpen() && document.querySelector('dialog:modal') === null) {
                event.preventDefault();
                this.close();
            }
        };
        this.onResize = () => {
            if (!this.media.matches) {
                this.close(false);
            }
        };
        this.onBeforeCache = () => this.close(false);
        document.addEventListener('keydown', this.onKeydown);
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
        this.media.addEventListener('change', this.onResize);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        this.media.removeEventListener('change', this.onResize);
        this.release();
    }

    isOpen() {
        return this.panelTarget.hasAttribute('data-open');
    }

    toggle() {
        this.isOpen() ? this.close() : this.open();
    }

    open() {
        this.panelTarget.setAttribute('data-open', '');
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        this.pageTargets.forEach((page) => {
            page.inert = true;
        });
        const first = this.panelTarget.querySelector('a[href], button');
        if (first) {
            first.focus();
        }
    }

    close(restoreFocus = true) {
        if (!this.isOpen()) {
            return;
        }
        this.panelTarget.removeAttribute('data-open');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        this.release();
        if (restoreFocus !== false) {
            this.toggleTarget.focus();
        }
    }

    release() {
        this.pageTargets.forEach((page) => {
            page.inert = false;
        });
    }
}
