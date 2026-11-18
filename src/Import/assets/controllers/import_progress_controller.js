/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const INTERVAL = 2000;

/**
 * Keeps the progress of an import up to date: reloads its Turbo Frame every
 * 2 s while the import is busy (reading, checking, queued, running, undoing)
 * and the tab is visible. Once the frame reports a state that is not busy,
 * polling stops and the import's page is opened again, which then shows the
 * next step.
 */
export default class extends Controller {
    static values = { url: String, done: String };

    connect() {
        this.visibilityChanged = this.visibilityChanged.bind(this);
        this.loaded = this.loaded.bind(this);
        this.missing = this.missing.bind(this);
        document.addEventListener('visibilitychange', this.visibilityChanged);
        this.element.addEventListener('turbo:frame-load', this.loaded);
        this.element.addEventListener('turbo:frame-missing', this.missing);
        this.schedule();
    }

    disconnect() {
        clearTimeout(this.timer);
        document.removeEventListener('visibilitychange', this.visibilityChanged);
        this.element.removeEventListener('turbo:frame-load', this.loaded);
        this.element.removeEventListener('turbo:frame-missing', this.missing);
    }

    // The answer was not the frame (the session ended and the sign-in page came back): show that page.
    missing(event) {
        clearTimeout(this.timer);
        event.preventDefault();
        event.detail.visit(event.detail.response);
    }

    busy() {
        const state = this.element.querySelector('[data-progress-active]');
        return state !== null && state.dataset.progressActive === 'true';
    }

    schedule() {
        clearTimeout(this.timer);
        if (!this.busy()) {
            this.finish();
            return;
        }
        if (document.hidden) {
            return;
        }
        this.timer = setTimeout(() => this.reload(), INTERVAL);
    }

    reload() {
        if (document.hidden || !this.busy()) {
            return;
        }
        if (this.element.getAttribute('src') === null) {
            this.element.src = this.urlValue;
        } else {
            this.element.reload();
        }
    }

    loaded() {
        this.schedule();
    }

    visibilityChanged() {
        if (document.hidden) {
            clearTimeout(this.timer);
        } else {
            this.reload();
        }
    }

    finish() {
        if (this.finished || !this.doneValue) {
            return;
        }
        this.finished = true;
        if (window.Turbo) {
            window.Turbo.visit(this.doneValue, { action: 'replace' });
        } else {
            window.location.assign(this.doneValue);
        }
    }
}
