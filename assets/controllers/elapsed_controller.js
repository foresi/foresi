/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The time since a stored start, as h:mm:ss, ticking once a second: the
 * running timer in the top bar. The start comes from the server, so a reload
 * or another device shows the same time; the page counts nothing itself.
 * Without JavaScript the text the server wrote stays.
 *
 *     <time data-controller="elapsed" data-elapsed-start-value="2026-11-09T10:00:00+00:00">0:12:05</time>
 */
export default class extends Controller {
    static values = { start: String };

    connect() {
        this.started = Date.parse(this.startValue);
        if (Number.isNaN(this.started)) {
            return;
        }
        this.tick();
        this.timer = window.setInterval(() => this.tick(), 1000);
    }

    disconnect() {
        window.clearInterval(this.timer);
    }

    tick() {
        const seconds = Math.max(0, Math.floor((Date.now() - this.started) / 1000));
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = seconds % 60;
        this.element.textContent = `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`;
    }
}
