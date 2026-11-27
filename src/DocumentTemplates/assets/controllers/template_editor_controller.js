/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const WAIT = 400;

/**
 * The template editor of Documents. A click on a variable puts its
 * placeholder at the cursor of the source, as PandaDoc, HubSpot and Pipedrive
 * Smart Docs insert a merge field from the list beside the text. While the
 * source, the paper or a margin changes, the preview frame is filled anew
 * with the examples after a short pause; a refused source shows why. Without
 * JavaScript the variables are there to copy and saving shows the preview.
 *
 *     <div data-controller="template-editor" data-template-editor-url-value="/settings/documents/templates/<id>/preview">
 */
export default class extends Controller {
    static targets = ['form', 'body', 'frame', 'error'];
    static values = { url: String };

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    insert({ params: { placeholder } }) {
        const body = this.bodyTarget;
        const start = body.selectionStart ?? body.value.length;
        const end = body.selectionEnd ?? start;
        body.focus();
        body.setRangeText(placeholder, start, end, 'end');
        body.dispatchEvent(new Event('input', { bubbles: true }));
    }

    changed() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.preview(), WAIT);
    }

    async preview() {
        this.request?.abort();
        this.request = new AbortController();
        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                body: new FormData(this.formTarget),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: this.request.signal,
            });
            if (!response.ok) {
                return;
            }
            const { html, error } = await response.json();
            this.errorTarget.textContent = error ?? '';
            this.errorTarget.hidden = !error;
            if (html !== null && html !== undefined) {
                this.frameTarget.srcdoc = html;
            }
        } catch (failure) {
            if (failure.name !== 'AbortError') {
                throw failure;
            }
        }
    }
}
