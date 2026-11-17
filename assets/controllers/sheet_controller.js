/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const PHONE = '(max-width: 639px)';
const MIN = 420;
const GAP = 180;
const STEP = 16;
const KEY = 'foresi.sheet.width';

/**
 * The width of a resizable Ui:Sheet; the dialog controller on the same element
 * does everything else. The layout spec (invoice detail as a side sheet): a grip
 * on the left edge sets the width from 420 px to the content panel minus 180 px,
 * remembered per browser in localStorage (a convenience, not domain state); the
 * arrow keys on the focused grip move it by 16 px (WCAG 2.5.7), Home and End go
 * to the ends (APG window splitter). On a phone the sheet fills the screen and
 * keeps no width.
 */
export default class extends Controller {
    static targets = ['grip'];
    static values = { resizable: Boolean };

    opened() {
        this.fit();
    }

    // Puts the remembered width back inside the bounds of this window.
    fit() {
        if (!this.element.open) {
            return;
        }
        if (!this.resizableValue || window.matchMedia(PHONE).matches) {
            this.element.style.removeProperty('width');
            return;
        }
        const stored = this.stored();
        if (stored === null) {
            this.element.style.removeProperty('width');
            this.describe(this.element.getBoundingClientRect().width);
            return;
        }
        this.apply(stored);
    }

    grab(event) {
        if (event.button !== 0 || !this.hasGripTarget) {
            return;
        }
        event.preventDefault();
        const grip = this.gripTarget;
        grip.setPointerCapture(event.pointerId);
        this.element.setAttribute('data-resizing', '');
        const move = (moved) => this.apply(window.innerWidth - moved.clientX);
        const drop = () => {
            grip.removeEventListener('pointermove', move);
            grip.removeEventListener('pointerup', drop);
            grip.removeEventListener('pointercancel', drop);
            this.element.removeAttribute('data-resizing');
            this.store();
        };
        grip.addEventListener('pointermove', move);
        grip.addEventListener('pointerup', drop);
        grip.addEventListener('pointercancel', drop);
    }

    key(event) {
        const width = this.element.getBoundingClientRect().width;
        const next = {
            // The grip is on the left edge: left makes the sheet wider.
            ArrowLeft: width + STEP,
            ArrowRight: width - STEP,
            Home: MIN,
            End: this.max(),
        }[event.key];
        if (next === undefined) {
            return;
        }
        event.preventDefault();
        this.apply(next);
        this.store();
    }

    apply(width) {
        const clamped = Math.round(Math.min(Math.max(width, MIN), this.max()));
        this.element.style.width = `${clamped}px`;
        this.describe(clamped);
    }

    describe(width) {
        if (this.hasGripTarget) {
            this.gripTarget.setAttribute('aria-valuemin', String(MIN));
            this.gripTarget.setAttribute('aria-valuemax', String(this.max()));
            this.gripTarget.setAttribute('aria-valuenow', String(Math.round(width)));
        }
    }

    // The content panel minus 180 px, never under the minimum.
    max() {
        const panel = document.getElementById('main');
        const room = panel ? panel.getBoundingClientRect().width : window.innerWidth;

        return Math.max(MIN, Math.round(Math.min(room, window.innerWidth) - GAP));
    }

    stored() {
        try {
            const width = Number.parseInt(window.localStorage.getItem(KEY) || '', 10);

            return Number.isFinite(width) ? width : null;
        } catch {
            return null;
        }
    }

    store() {
        try {
            window.localStorage.setItem(KEY, String(Math.round(this.element.getBoundingClientRect().width)));
        } catch {
            // Without storage the width lasts until the page changes.
        }
    }
}
