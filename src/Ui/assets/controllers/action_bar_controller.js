/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Marks the floating action bar with data-stuck while the page scrolls under
 * it, so it shows a shadow then and stays flat when it sits under the content.
 * The bar floats 8 or 12 px above the bottom of the viewport; the observed area
 * ends one pixel above that, so a stuck bar is never fully inside it. Without
 * this controller the bar works the same, only without the shadow.
 */
export default class extends Controller {
    connect() {
        if (!('IntersectionObserver' in window)) {
            return;
        }
        const offset = parseFloat(getComputedStyle(this.element).bottom) || 0;
        this.observer = new IntersectionObserver(([entry]) => {
            const stuck = entry.intersectionRatio < 1 && entry.boundingClientRect.top > 0;
            this.element.toggleAttribute('data-stuck', stuck);
        }, { threshold: [1], rootMargin: `0px 0px -${offset + 1}px 0px` });
        this.observer.observe(this.element);
    }

    disconnect() {
        this.observer?.disconnect();
        this.element.removeAttribute('data-stuck');
    }
}
