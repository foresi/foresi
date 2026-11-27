/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * The square crop of a profile photo before it is sent (GitHub, Google Account:
 * choose, crop, save). The chosen image fills a square canvas with the circle
 * the avatar shows over it; dragging moves it, the range zooms it. On submit the
 * square is drawn at "side" pixels and sent as a JPEG in place of the file, so
 * the upload is small. Without JavaScript, or for a file the browser cannot draw,
 * the file goes as it is and the server takes the middle square.
 *
 *     <form data-controller="photo-crop" data-photo-crop-side-value="512">
 *         <input type="file" data-photo-crop-target="input" data-action="change->photo-crop#load">
 *         <div data-photo-crop-target="editor" hidden><canvas data-photo-crop-target="canvas"></canvas>
 *             <input type="range" data-photo-crop-target="zoom" data-action="input->photo-crop#draw"></div>
 */
export default class extends Controller {
    static targets = ['input', 'editor', 'canvas', 'zoom'];
    static values = { side: { type: Number, default: 512 } };

    connect() {
        this.image = null;
        this.offset = { x: 0, y: 0 };
        this.pointer = null;
        this.onSubmit = this.crop.bind(this);
        // Capture: the crop goes into the file input before the dialog reads the form.
        this.element.addEventListener('submit', this.onSubmit, true);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit, true);
        this.release();
    }

    async load() {
        this.release();
        const file = this.inputTarget.files && this.inputTarget.files[0];
        if (!file || !file.type.startsWith('image/')) {
            this.editorTarget.hidden = true;
            return;
        }
        this.url = URL.createObjectURL(file);
        const image = new Image();
        image.decoding = 'async';
        image.src = this.url;
        try {
            await image.decode();
        } catch {
            this.editorTarget.hidden = true;
            return;
        }
        this.image = image;
        this.offset = { x: 0, y: 0 };
        this.zoomTarget.value = '1';
        this.editorTarget.hidden = false;
        this.draw();
    }

    grab(event) {
        if (!this.image) {
            return;
        }
        this.pointer = { x: event.clientX, y: event.clientY };
        event.currentTarget.setPointerCapture(event.pointerId);
    }

    drag(event) {
        if (!this.pointer) {
            return;
        }
        // The canvas is drawn at its own width; the box on screen may be smaller.
        const scale = this.canvasTarget.width / this.canvasTarget.getBoundingClientRect().width;
        this.offset.x += (event.clientX - this.pointer.x) * scale;
        this.offset.y += (event.clientY - this.pointer.y) * scale;
        this.pointer = { x: event.clientX, y: event.clientY };
        this.draw();
    }

    drop() {
        this.pointer = null;
    }

    draw() {
        if (!this.image) {
            return;
        }
        this.paint(this.canvasTarget, this.canvasTarget.width);
    }

    /** Draws the image covering a square canvas, zoomed and moved, never leaving a gap. */
    paint(canvas, side) {
        const { naturalWidth: width, naturalHeight: height } = this.image;
        const ratio = side / this.canvasTarget.width;
        const scale = (side / Math.min(width, height)) * Number(this.zoomTarget.value || 1);
        const drawn = { width: width * scale, height: height * scale };
        const limit = { x: (drawn.width - side) / 2, y: (drawn.height - side) / 2 };
        this.offset.x = Math.max(-limit.x / ratio, Math.min(limit.x / ratio, this.offset.x));
        this.offset.y = Math.max(-limit.y / ratio, Math.min(limit.y / ratio, this.offset.y));
        const context = canvas.getContext('2d');
        context.fillStyle = '#fff';
        context.fillRect(0, 0, side, side);
        context.imageSmoothingQuality = 'high';
        context.drawImage(this.image, (side - drawn.width) / 2 + this.offset.x * ratio, (side - drawn.height) / 2 + this.offset.y * ratio, drawn.width, drawn.height);
    }

    crop(event) {
        if (!this.image || typeof DataTransfer === 'undefined') {
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const canvas = document.createElement('canvas');
        canvas.width = this.sideValue;
        canvas.height = this.sideValue;
        this.paint(canvas, this.sideValue);
        canvas.toBlob((blob) => {
            if (blob) {
                const transfer = new DataTransfer();
                transfer.items.add(new File([blob], 'photo.jpg', { type: 'image/jpeg' }));
                this.inputTarget.files = transfer.files;
            }
            // Send it again, now with the square in the input; the dialog takes this submit.
            this.image = null;
            this.element.requestSubmit(event.submitter && event.submitter.form === this.element ? event.submitter : undefined);
        }, 'image/jpeg', 0.9);
    }

    release() {
        if (this.url) {
            URL.revokeObjectURL(this.url);
            this.url = null;
        }
    }
}
