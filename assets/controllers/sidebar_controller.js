/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const NARROW = '(max-width: 899px)';

/**
 * Narrow screens: the sidebar is a drawer behind the menu button. While it is
 * open the page behind it is inert, Escape closes it and focus returns to the
 * button. On wide screens the sidebar is always shown and this does nothing.
 */
export default class extends Controller {
    static targets = ['panel', 'scrim', 'toggle', 'page'];

    connect() {
        this.media = window.matchMedia(NARROW);
        this.onKeydown = (event) => {
            if (event.key === 'Escape' && this.isOpen()) {
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
        this.scrimTarget.hidden = false;
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
        this.scrimTarget.hidden = true;
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
