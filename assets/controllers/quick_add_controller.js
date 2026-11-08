/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The one-line form at the end of a section that adds a task by its title:
 * Enter sends it, an empty line sends nothing. After the page comes back the
 * line the task was added from has the server's autofocus; Turbo scrolls to
 * the top once it rendered, so the line is brought back into view.
 */
export default class extends Controller {
    static targets = ['title'];

    connect() {
        if (this.titleTarget.hasAttribute('autofocus')) {
            this.frame = requestAnimationFrame(() => {
                this.titleTarget.focus({ preventScroll: true });
                this.titleTarget.scrollIntoView({ block: 'center', inline: 'nearest' });
            });
        }
    }

    disconnect() {
        cancelAnimationFrame(this.frame);
    }

    submit(event) {
        if (this.titleTarget.value.trim() === '') {
            event.preventDefault();
        }
    }
}
