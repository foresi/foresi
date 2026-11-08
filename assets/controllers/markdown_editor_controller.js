/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const MAX_OPTIONS = 8;
const MAX_QUERY = 50;
const IMAGES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
// What would end the text of a Markdown link or image early.
const label = (name) => name.replace(/[\r\n]+/g, ' ').replace(/([[\]\\])/g, '\\$1');
const fold = (value) => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();

/**
 * A text area for Markdown (see the Ui:MarkdownEditor component).
 *
 * Write and Preview are tabs (WAI-ARIA APG tabs): Preview sends the text to the
 * server, which answers the sanitised HTML. The toolbar wraps or prefixes the
 * selection, also with Ctrl/Cmd+B, I and K; the text goes in with
 * execCommand('insertText') so that undo keeps working, else setRangeText.
 *
 * Given a mentions URL, "@" at the start of a word opens the people whose name
 * holds what follows (WAI-ARIA APG combobox with list autocomplete): the focus
 * stays in the text area, Down and Up move, Enter or a click inserts the
 * mention, Escape closes, Tab leaves.
 *
 * Given an upload URL, an image pasted from the clipboard or files dropped on
 * the text area are uploaded to the record, as on GitHub: a placeholder
 * "![Uploading name…]()" holds their place and becomes "![name](url)" for an
 * image, "[name](url)" for any other file; a refused file takes its
 * placeholder away and the server's message shows under the text area.
 */
export default class extends Controller {
    static targets = ['bar', 'toolbar', 'writeTab', 'previewTab', 'writePanel', 'previewPanel', 'input', 'list', 'empty', 'status', 'option', 'nothing', 'failed', 'rendered', 'uploadError'];
    static values = {
        previewUrl: String, token: String, mentionsUrl: String, found: String,
        uploadUrl: String, uploadToken: String, uploadKind: String, uploadOwner: String, uploading: String, uploadFailed: String,
    };

    connect() {
        this.barTarget.hidden = false;
        this.writePanelTarget.setAttribute('role', 'tabpanel');
        this.writePanelTarget.setAttribute('aria-labelledby', this.writeTabTarget.id);
        this.matches = [];
        this.active = -1;
        this.mention = null;
        if (this.hasMentions) {
            const input = this.inputTarget;
            input.setAttribute('role', 'combobox');
            input.setAttribute('aria-autocomplete', 'list');
            input.setAttribute('aria-expanded', 'false');
            input.setAttribute('aria-controls', this.listTarget.id);
        }
    }

    disconnect() {
        this.request?.abort();
    }

    get canUpload() {
        return this.hasUploadUrlValue && this.uploadUrlValue !== '';
    }

    get hasMentions() {
        return this.hasMentionsUrlValue && this.mentionsUrlValue !== '' && this.hasListTarget;
    }

    // Tabs

    write() {
        this.select(false);
        this.inputTarget.focus();
    }

    async preview() {
        this.close();
        const height = this.inputTarget.offsetHeight;
        this.select(true);
        this.previewPanelTarget.style.minHeight = `${height}px`;
        this.renderedTarget.replaceChildren();
        this.failedTarget.hidden = true;
        const text = this.inputTarget.value;
        this.nothingTarget.hidden = text.trim() !== '';
        if (text.trim() === '') {
            return;
        }
        this.request?.abort();
        this.request = new AbortController();
        const body = new FormData();
        body.set('text', text);
        body.set('_token', this.tokenValue);
        try {
            const response = await fetch(this.previewUrlValue, { method: 'POST', body, headers: { Accept: 'text/html' }, signal: this.request.signal });
            if (!response.ok) {
                throw new Error(`Preview failed: ${response.status}`);
            }
            // The server's Markdown port answers sanitised HTML only.
            this.renderedTarget.innerHTML = await response.text();
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.failedTarget.hidden = false;
            }
        }
    }

    select(preview) {
        this.writeTabTarget.setAttribute('aria-selected', preview ? 'false' : 'true');
        this.previewTabTarget.setAttribute('aria-selected', preview ? 'true' : 'false');
        this.writeTabTarget.tabIndex = preview ? -1 : 0;
        this.previewTabTarget.tabIndex = preview ? 0 : -1;
        this.writePanelTarget.hidden = preview;
        this.previewPanelTarget.hidden = !preview;
        this.toolbarTarget.hidden = preview;
        if (!preview) {
            this.request?.abort();
        }
    }

    tabKey(event) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }
        event.preventDefault();
        const toPreview = event.key === 'End' || (event.key !== 'Home' && this.previewTabTarget.getAttribute('aria-selected') !== 'true');
        if (toPreview) {
            this.previewTabTarget.focus();
            this.preview();
        } else {
            this.select(false);
            this.writeTabTarget.focus();
        }
    }

    // Formatting

    format({ params: { format } }) {
        const input = this.inputTarget;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selected = input.value.slice(start, end);
        if (format === 'bold') {
            this.wrap('**', '**');
        } else if (format === 'italic') {
            this.wrap('_', '_');
        } else if (format === 'code') {
            if (selected.includes('\n')) {
                this.wrap('```\n', '\n```');
            } else {
                this.wrap('`', '`');
            }
        } else if (format === 'link') {
            const text = `[${selected}](url)`;
            const url = start + selected.length + 3;
            this.replace(start, end, text, url, url + 3);
        } else if (format === 'list') {
            this.prefix('- ');
        } else if (format === 'quote') {
            this.prefix('> ');
        }
    }

    wrap(before, after) {
        const input = this.inputTarget;
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const selected = input.value.slice(start, end);
        this.replace(start, end, before + selected + after, start + before.length, start + before.length + selected.length);
    }

    prefix(marker) {
        const input = this.inputTarget;
        const value = input.value;
        const start = value.lastIndexOf('\n', input.selectionStart - 1) + 1;
        let end = input.selectionEnd;
        // A selection that ends at the start of a line leaves that line alone.
        if (end > input.selectionStart && value[end - 1] === '\n') {
            end -= 1;
        }
        const lineEnd = value.indexOf('\n', end);
        const stop = lineEnd === -1 ? value.length : lineEnd;
        const lines = value.slice(start, stop).split('\n').map((line) => marker + line).join('\n');
        if (input.selectionStart === input.selectionEnd) {
            const caret = input.selectionStart + marker.length;
            this.replace(start, stop, lines, caret, caret);
        } else {
            this.replace(start, stop, lines, start, start + lines.length);
        }
    }

    /**
     * Puts the text in place of the range and selects selectionStart..selectionEnd
     * of the new value. insertText keeps the browser's undo history.
     */
    replace(start, end, text, selectionStart, selectionEnd) {
        const input = this.inputTarget;
        input.focus();
        input.setSelectionRange(start, end);
        let inserted = false;
        try {
            inserted = document.execCommand('insertText', false, text);
        } catch {
            inserted = false;
        }
        if (!inserted || input.value.slice(start, start + text.length) !== text) {
            input.setRangeText(text, start, end, 'end');
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        input.setSelectionRange(selectionStart, selectionEnd);
    }

    // Keys

    keydown(event) {
        if (event.isComposing) {
            return;
        }
        if (this.isOpen() && this.navigate(event)) {
            return;
        }
        if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey) {
            const format = { b: 'bold', i: 'italic', k: 'link' }[event.key.toLowerCase()];
            if (format) {
                event.preventDefault();
                this.format({ params: { format } });
            }
        }
    }

    /** @return {boolean} whether the key was the listbox's */
    navigate(event) {
        const count = this.listTarget.children.length;
        const plain = !event.ctrlKey && !event.metaKey && !event.altKey && !event.shiftKey;
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            this.dismissed = this.mention?.at ?? null;
            this.close();
            return true;
        }
        if (event.key === 'Tab') {
            this.close();
            return true;
        }
        if (count === 0 || !plain) {
            return false;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.activate((this.active + 1) % count);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.activate((this.active - 1 + count) % count);
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            this.accept(this.matches[this.active]);
        } else {
            return false;
        }

        return true;
    }

    // Files

    paste(event) {
        const files = Array.from(event.clipboardData?.files || []);
        if (!this.canUpload || files.length === 0) {
            return;
        }
        event.preventDefault();
        this.uploadAll(files);
    }

    dragOver(event) {
        if (this.canUpload && Array.from(event.dataTransfer?.types || []).includes('Files')) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        }
    }

    drop(event) {
        const files = Array.from(event.dataTransfer?.files || []);
        if (!this.canUpload || files.length === 0) {
            return;
        }
        event.preventDefault();
        this.uploadAll(files);
    }

    uploadAll(files) {
        this.uploadErrorTarget.hidden = true;
        this.uploadErrorTarget.textContent = '';
        const input = this.inputTarget;
        const placeholders = files.map((file) => `![${label(this.uploadingValue.replace('__NAME__', file.name || 'image'))}]()`);
        const text = placeholders.join('\n');
        const caret = input.selectionStart + text.length;
        this.replace(input.selectionStart, input.selectionEnd, text, caret, caret);
        files.forEach((file, index) => this.upload(file, placeholders[index]));
    }

    async upload(file, placeholder) {
        const body = new FormData();
        body.set('file', file);
        body.set('owner_kind', this.uploadKindValue);
        body.set('owner_id', this.uploadOwnerValue);
        body.set('_token', this.uploadTokenValue);
        try {
            const response = await fetch(this.uploadUrlValue, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = (response.headers.get('Content-Type') || '').includes('application/json') ? await response.json() : {};
            if (response.status !== 201 || typeof data.url !== 'string') {
                throw new Error(typeof data.error === 'string' ? data.error : this.uploadFailedValue);
            }
            const link = `[${label(data.name || file.name)}](${data.url})`;
            this.swap(placeholder, IMAGES.includes(data.mediaType) ? `!${link}` : link);
        } catch (error) {
            this.swap(placeholder, '');
            this.uploadErrorTarget.textContent = error instanceof Error && error.message !== '' && !(error instanceof TypeError) ? error.message : this.uploadFailedValue;
            this.uploadErrorTarget.hidden = false;
        }
    }

    /**
     * Puts the text in place of the placeholder; the caret stays where it is and
     * the focus where the person has gone meanwhile.
     */
    swap(placeholder, text) {
        const input = this.inputTarget;
        const at = input.value.indexOf(placeholder);
        if (at === -1) {
            return;
        }
        const end = at + placeholder.length;
        // A caret after the placeholder moves with it; one inside it goes to the end of the text.
        const moved = (position) => (position >= end ? position + text.length - placeholder.length : Math.min(position, at + text.length));
        const [start, stop] = [moved(input.selectionStart), moved(input.selectionEnd)];
        input.setRangeText(text, at, end);
        input.setSelectionRange(start, stop);
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // Mentions

    async suggest() {
        if (!this.hasMentions) {
            return;
        }
        const mention = this.mentionAtCaret();
        if (mention === null || mention.at === this.dismissed) {
            if (mention === null) {
                this.dismissed = null;
            }
            this.close();
            return;
        }
        this.mention = mention;
        const people = await this.people();
        // The text may have moved on while the people were loading.
        if (this.mention !== mention) {
            return;
        }
        const query = fold(mention.query);
        const matches = people.filter((person) => fold(person.name).includes(query)).slice(0, MAX_OPTIONS);
        if (matches.length === 0 && /\s/.test(mention.query)) {
            this.close();
            return;
        }
        this.show(matches);
    }

    /** The "@" before the caret that starts a word, and what follows it. */
    mentionAtCaret() {
        const input = this.inputTarget;
        if (input.selectionStart !== input.selectionEnd) {
            return null;
        }
        const before = input.value.slice(0, input.selectionStart);
        const match = before.match(/(^|\s)@([^@\n[\]]*)$/u);
        if (!match || match[2].length > MAX_QUERY || /^\s/.test(match[2])) {
            return null;
        }

        return { at: before.length - match[2].length - 1, query: match[2] };
    }

    async people() {
        if (!this.loading) {
            this.loading = fetch(this.mentionsUrlValue, { headers: { Accept: 'application/json' } })
                .then((response) => (response.ok ? response.json() : { results: [] }))
                .then((data) => (Array.isArray(data.results) ? data.results : []))
                .catch(() => {
                    this.loading = null;
                    return [];
                });
        }

        return this.loading;
    }

    show(matches) {
        this.matches = matches;
        this.listTarget.replaceChildren(...matches.map((person, index) => this.optionFor(person, index)));
        this.statusTarget.textContent = matches.length === 0 ? this.emptyTarget.textContent.trim() : this.foundValue.replace('__COUNT__', matches.length);
        this.place();
        this.listTarget.hidden = matches.length === 0;
        this.emptyTarget.hidden = matches.length > 0;
        this.inputTarget.setAttribute('aria-expanded', matches.length > 0 ? 'true' : 'false');
        if (matches.length > 0) {
            this.activate(0);
        } else {
            this.active = -1;
            this.inputTarget.removeAttribute('aria-activedescendant');
        }
    }

    optionFor(person, index) {
        const item = this.optionTarget.content.firstElementChild.cloneNode(true);
        item.id = `${this.listTarget.id}-${index}`;
        item.querySelector('[data-initials]').textContent = person.initials;
        item.querySelector('[data-name]').textContent = person.name;
        // Before the blur of the text area, which would close the list.
        item.addEventListener('mousedown', (event) => event.preventDefault());
        item.addEventListener('click', () => this.accept(person));
        return item;
    }

    activate(index) {
        Array.from(this.listTarget.children).forEach((item, position) => {
            item.setAttribute('aria-selected', position === index ? 'true' : 'false');
        });
        this.active = index;
        const item = this.listTarget.children[index];
        this.inputTarget.setAttribute('aria-activedescendant', item.id);
        item.scrollIntoView({ block: 'nearest' });
    }

    accept(person) {
        const mention = this.mention;
        this.close();
        if (!mention || !person) {
            return;
        }
        const name = person.name.replace(/[[\]\r\n]/g, ' ').trim();
        const text = `@[${name}](membership:${person.id}) `;
        const caret = mention.at + text.length;
        this.replace(mention.at, this.inputTarget.selectionStart, text, caret, caret);
        this.close();
    }

    close() {
        this.mention = null;
        this.active = -1;
        if (!this.hasMentions) {
            return;
        }
        this.listTarget.hidden = true;
        this.emptyTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }

    isOpen() {
        return this.hasMentions && (!this.listTarget.hidden || !this.emptyTarget.hidden);
    }

    /** Under the line of the caret, measured on a hidden copy of the text area. */
    place() {
        const input = this.inputTarget;
        const style = window.getComputedStyle(input);
        const mirror = document.createElement('div');
        ['boxSizing', 'width', 'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderTopWidth', 'borderRightWidth', 'borderBottomWidth', 'borderLeftWidth',
            'fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'letterSpacing', 'lineHeight', 'textTransform', 'wordSpacing', 'tabSize'].forEach((property) => {
            mirror.style[property] = style[property];
        });
        Object.assign(mirror.style, { position: 'absolute', visibility: 'hidden', top: '0', left: '0', whiteSpace: 'pre-wrap', overflowWrap: 'break-word', borderStyle: 'solid' });
        mirror.textContent = input.value.slice(0, input.selectionStart);
        const marker = document.createElement('span');
        marker.textContent = '​';
        mirror.append(marker);
        this.writePanelTarget.append(mirror);
        const lineHeight = parseFloat(style.lineHeight) || marker.offsetHeight;
        const top = Math.min(marker.offsetTop - input.scrollTop + lineHeight, input.offsetHeight) + 4;
        const width = this.listTarget.offsetWidth || 256;
        const left = Math.max(0, Math.min(marker.offsetLeft - input.scrollLeft, input.offsetWidth - width));
        mirror.remove();
        [this.listTarget, this.emptyTarget].forEach((element) => {
            element.style.top = `${top}px`;
            element.style.left = `${left}px`;
        });
    }
}
