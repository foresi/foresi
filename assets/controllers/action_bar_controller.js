/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Marks the docked action bar with data-stuck while the page scrolls under it,
 * so it shows a shadow then and stays flat when it sits under the content.
 * The bar is positioned one pixel past the bottom of the viewport: while it is
 * stuck that pixel is hidden, so it is not fully visible. Without this
 * controller the bar works the same, only without the shadow.
 */
export default class extends Controller {
    connect() {
        if (!('IntersectionObserver' in window)) {
            return;
        }
        this.observer = new IntersectionObserver(([entry]) => {
            const stuck = entry.intersectionRatio < 1 && entry.boundingClientRect.top > 0;
            this.element.toggleAttribute('data-stuck', stuck);
        }, { threshold: [1] });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        this.element.removeAttribute('data-stuck');
    }
}
