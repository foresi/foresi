/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const IMAGE = 'img[src^="/attachments/"]';

/**
 * The files of a record (see work/task/_attachments.html.twig).
 *
 * Adding follows the improved file upload of GOV.UK: the drop area is always
 * there, the button chooses one or more files. Each file is sent on its own
 * with XMLHttpRequest, whose upload progress shows in its bar; a refused file
 * keeps its bar with the server's message. Once files are in, the list (a
 * Turbo Frame) loads again. Without JavaScript the form uploads one file.
 *
 * Viewing: an image of the list or of a text, and a PDF of the list, open in
 * the viewer dialog with their name and a download link; a PDF in an iframe.
 */
export default class extends Controller {
    static targets = ['form', 'input', 'drop', 'hint', 'choose', 'submit', 'queue', 'item', 'frame', 'viewer', 'stage', 'download'];

    connect() {
        if (this.hasInputTarget) {
            this.inputTarget.multiple = true;
            this.inputTarget.hidden = true;
            this.hintTarget.hidden = false;
            this.chooseTarget.hidden = false;
            this.submitTarget.hidden = true;
        }
    }

    // Choosing and dropping

    choose() {
        this.inputTarget.click();
    }

    chosen() {
        this.uploadAll(Array.from(this.inputTarget.files));
        this.inputTarget.value = '';
    }

    submit(event) {
        // Only without the button that chooses files, as a plain form.
        if (this.inputTarget.files.length > 0) {
            event.preventDefault();
            this.chosen();
        }
    }

    dragEnter(event) {
        if (this.carriesFiles(event)) {
            event.preventDefault();
            this.dropTarget.dataset.dragging = '';
        }
    }

    dragOver(event) {
        if (this.carriesFiles(event)) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        }
    }

    dragLeave(event) {
        if (!this.dropTarget.contains(event.relatedTarget)) {
            delete this.dropTarget.dataset.dragging;
        }
    }

    drop(event) {
        delete this.dropTarget.dataset.dragging;
        const files = Array.from(event.dataTransfer?.files || []);
        if (files.length === 0) {
            return;
        }
        event.preventDefault();
        this.uploadAll(files);
    }

    carriesFiles(event) {
        return Array.from(event.dataTransfer?.types || []).includes('Files');
    }

    // Uploading

    async uploadAll(files) {
        if (files.length === 0) {
            return;
        }
        const results = await Promise.all(files.map((file) => this.upload(file)));
        if (results.some(Boolean)) {
            this.reload();
        }
    }

    /** @return {Promise<boolean>} whether the file was attached */
    upload(file) {
        const item = this.itemTarget.content.firstElementChild.cloneNode(true);
        const label = item.querySelector('span');
        const bar = item.querySelector('[role="progressbar"]');
        const fill = bar.firstElementChild;
        const error = item.querySelector('[data-upload-error]');
        label.textContent = file.name;
        bar.setAttribute('aria-label', file.name);
        this.queueTarget.append(item);

        const data = new FormData(this.formTarget);
        // Each request carries its own file only, whatever the input of the plain form is named.
        data.delete(this.inputTarget.name);
        data.delete('file');
        data.delete('return');
        data.set('file', file);

        return new Promise((resolve) => {
            const request = new XMLHttpRequest();
            const progress = (share) => {
                const percent = Math.round(share * 100);
                fill.style.width = `${percent}%`;
                bar.setAttribute('aria-valuenow', String(percent));
            };
            const fail = (message) => {
                error.textContent = message;
                error.hidden = false;
                item.firstElementChild.hidden = true;
                resolve(false);
            };
            request.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    progress(event.loaded / event.total);
                }
            });
            request.addEventListener('load', () => {
                let answer = {};
                try {
                    answer = JSON.parse(request.responseText);
                } catch {
                    answer = {};
                }
                if (request.status === 201) {
                    progress(1);
                    item.dataset.uploaded = '';
                    resolve(true);
                } else {
                    fail(`${file.name}: ${typeof answer.error === 'string' ? answer.error : this.formTarget.dataset.failed}`);
                }
            });
            request.addEventListener('error', () => fail(`${file.name}: ${this.formTarget.dataset.failed}`));
            request.open('POST', this.formTarget.action);
            request.setRequestHeader('Accept', 'application/json');
            request.send(data);
        });
    }

    /** Loads the list again and takes away the bars of the files now in it. */
    reload() {
        // An upload without a list of its own (the dialog of received documents) leaves the bars;
        // the page under it loads again once the dialog closes.
        if (!this.hasFrameTarget) {
            return;
        }
        const frame = this.frameTarget;
        frame.addEventListener('turbo:frame-load', () => {
            this.queueTarget.querySelectorAll('[data-uploaded]').forEach((item) => item.remove());
        }, { once: true });
        if (typeof frame.reload === 'function' && frame.src) {
            frame.reload();
        } else {
            frame.src = window.location.pathname + window.location.search;
        }
    }

    // Viewing

    open(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.currentTarget;
        if (this.show(link.dataset.attachmentPreview, link.getAttribute('href'), link.dataset.attachmentName, link)) {
            event.preventDefault();
        }
    }

    /** An image in a rendered text: only the installation's own files show there. */
    openImage(event) {
        const image = event.target instanceof Element ? event.target.closest(IMAGE) : null;
        if (image === null || image.closest('[data-attachment-list], [data-markdown-editor], dialog') !== null || image.closest('a') !== null) {
            return;
        }
        event.preventDefault();
        this.show('image', image.getAttribute('src'), image.getAttribute('alt') || '', image);
    }

    show(kind, url, name, opener) {
        const dialog = this.application.getControllerForElementAndIdentifier(this.viewerTarget, 'dialog');
        if (dialog === null || !['image', 'pdf'].includes(kind)) {
            return false;
        }
        const title = this.viewerTarget.querySelector('[data-dialog-target="title"]');
        if (name) {
            title.textContent = name;
        }
        let content;
        if (kind === 'image') {
            content = document.createElement('img');
            content.className = 'block h-auto max-h-[70dvh] max-w-full object-contain';
            content.alt = name;
        } else {
            content = document.createElement('iframe');
            content.className = 'block h-dvh max-h-[70dvh] w-full border-0';
            content.title = name;
        }
        content.src = url;
        this.stageTarget.replaceChildren(content);
        this.downloadTarget.href = url;
        this.downloadTarget.setAttribute('download', name);

        return dialog.open(opener);
    }

    closed(event) {
        if (event.target === this.viewerTarget) {
            this.stageTarget.replaceChildren();
        }
    }
}
