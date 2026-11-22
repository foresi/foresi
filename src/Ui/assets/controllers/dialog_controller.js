/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';
import { config, session, visit } from '@hotwired/turbo';

const HEADER = 'X-Foresi-Dialog';
const PHONE = '(max-width: 639px)';
const FIELDS = 'input:not([type="hidden"]), select, textarea';
const FOCUSABLE = `${FIELDS}, button, a[href], [tabindex]:not([tabindex="-1"])`;
const HISTORY_WAIT = 400;

// One open dialog per layer, across every instance of this controller: a sheet, and a
// dialog that may open over it (a confirmation or a small form started in the sheet).
const current = { sheet: null, dialog: null };
// The controller stepping back out of its own history entry, which no other one answers.
let popper = null;

// A layer still held by a host that left the page (its close event never reached it) is free.
const release = (layer) => {
    if (current[layer] !== null && !current[layer].element.isConnected) {
        current[layer] = null;
    }
};
const visible = (element) => (typeof element.checkVisibility === 'function' ? element.checkVisibility() : element.getClientRects().length > 0);
const isType = (response, type) => (response.headers.get('Content-Type') || '').includes(type);

/**
 * The modal dialog of Ui:Dialog, on the native <dialog> with showModal(): the
 * browser makes the page inert, keeps the focus inside and turns Escape into a
 * close request. This controller adds what the browser leaves out:
 *
 * - The remote host loads a page as a dialog. A link with data-dialog opens its
 *   URL in place; without JavaScript, or with a modified click, it is the page.
 *   Its form posts with fetch: 422 swaps the dialog, a JSON answer finishes it
 *   by reloading the page (or following the server's location, or sending an
 *   event with the result). A dialog the server answers marked
 *   data-dialog-reload="1" took a change and stays open; the page under it is
 *   loaded again once it closes.
 *   The same code runs on Turbo and on plain pages.
 * - Focus goes to the first problem, else the autofocus element, else the first
 *   field, else the title; on close it returns to the opener, or to the field a
 *   result was put in.
 * - A changed form asks inside the dialog before it is closed. Only a dialog
 *   without fields closes on a click on the backdrop (Safari has no closedby).
 * - On a phone the Back button closes the dialog: one history entry while it
 *   is open, gone again when it closes.
 * - Turbo: closed before a page is cached or rendered, the open state is kept
 *   out of morphs, so Back never brings a dialog back.
 * - The confirm variant answers data-turbo-confirm and dialog:confirm events.
 * - Ui:Sheet runs on the same code in the layer "sheet": its remote host follows
 *   a[data-sheet] links and takes dialog#sheet-page from the answer. A dialog may
 *   open over a sheet; Escape, Back and the focus belong to the top one. The
 *   header names the layer that asks: "sheet" or "1" (a dialog).
 * - A record (layout/record.html.twig, data-overlay-record; docs/design/overlays.md)
 *   puts its own URL in the address bar while it is open, on every screen, and Back
 *   closes it. Its links in a [data-in-place] area and the answers of its forms load
 *   in the same host (edit in place); Open as page closes it and visits the URL; the
 *   page under it is loaded again once it closes after a change.
 */
export default class extends Controller {
    static targets = ['title', 'body', 'actions', 'discard', 'keep', 'text', 'accept', 'reject'];
    static values = {
        fields: Boolean,
        dismissable: Boolean,
        remote: Boolean,
        confirm: Boolean,
        failed: String,
        layer: { type: String, default: 'dialog' },
        link: { type: String, default: 'a[data-dialog]' },
        source: { type: String, default: 'dialog#dialog-page' },
    };

    connect() {
        // Safari keeps a dialog that was taken out and put back open, but no longer modal.
        // Only the catalogue shows one open in the page on purpose.
        if (this.element.open && !this.element.hasAttribute('data-dialog-preview')) {
            this.element.close();
        }
        if (this.confirmValue) {
            config.forms.confirm = (message, element) => this.confirm({
                title: message,
                text: element.dataset.confirmText,
                accept: element.dataset.confirmAccept,
                cancel: element.dataset.confirmCancel,
                tone: element.dataset.confirmTone,
            });
        }
        if (this.remoteValue) {
            // A form that can hand a step to a dialog says so, so the server may answer with one.
            document.querySelectorAll('input[data-dialog-capable]').forEach((input) => { input.value = '1'; });
            const auto = document.querySelector(`${this.linkValue}[data-dialog-autoopen]`);
            if (auto) {
                auto.removeAttribute('data-dialog-autoopen');
                this.load(auto.href, auto);
            }
        }
    }

    disconnect() {
        if (current[this.layerValue] === this) {
            current[this.layerValue] = null;
        }
    }

    // Opening

    follow(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.target instanceof Element ? event.target.closest(this.linkValue) : null;
        if (link === null || this.element.contains(link)) {
            return;
        }
        event.preventDefault();
        this.load(link.href, link);
    }

    async load(url, opener) {
        release(this.layerValue);
        if (current[this.layerValue] !== null || this.blocked() || this.loading) {
            return;
        }
        this.loading = true;
        try {
            const response = await fetch(url, { headers: { [HEADER]: this.layerHeader(), Accept: 'text/html, application/json' }, credentials: 'same-origin' });
            if (response.ok && isType(response, 'application/json')) {
                // Nothing to ask (a trashed invoice, say): go where the server sends, its message waits there.
                const { location } = await response.json();
                this.go(location || url);
                return;
            }
            if (!response.ok || !isType(response, 'text/html')) {
                throw new Error(`The dialog answered ${response.status}.`);
            }
            const html = await response.text();
            if (!this.element.isConnected) {
                // Turbo rendered another page while the answer was on its way (the reload after
                // a dialog closed, say): this host is gone, the one on the new page opens next.
                return;
            }
            this.fill(html);
            this.url = url;
        } catch {
            // The page itself still works.
            window.location.assign(url);
            return;
        } finally {
            this.loading = false;
        }
        this.open(opener);
    }

    layerHeader() {
        return this.layerValue === 'sheet' ? 'sheet' : '1';
    }

    // The host shows a record of layout/record.html.twig.
    get record() {
        return this.remoteValue && this.element.hasAttribute('data-overlay-record');
    }

    // Loads a URL into this open host in place of what it shows: the next step of a record
    // (its edit form, the detail again after saving, another tab). An answer that is no
    // record of this layer is a page: the host closes and the browser goes there.
    async swap(url) {
        if (this.loading) {
            return;
        }
        this.loading = true;
        this.element.setAttribute('aria-busy', 'true');
        let html = null;
        try {
            const response = await fetch(url, { headers: { [HEADER]: this.layerHeader(), Accept: 'text/html, application/json' }, credentials: 'same-origin' });
            if (response.ok && isType(response, 'application/json')) {
                const { location } = await response.json();
                this.loading = false;
                await this.leave(location || url);
                return;
            }
            if (response.ok && !isType(response, 'text/html')) {
                // A file (the PDF of save and PDF) is no step of the record: the browser opens it.
                this.loading = false;
                this.element.removeAttribute('aria-busy');
                await this.leave(url, 'advance');
                return;
            }
            if (!response.ok) {
                throw new Error(`The dialog answered ${response.status}.`);
            }
            html = await response.text();
        } catch {
            this.loading = false;
            this.element.removeAttribute('aria-busy');
            this.fail();
            return;
        }
        this.loading = false;
        this.element.removeAttribute('aria-busy');
        if (new DOMParser().parseFromString(html, 'text/html').querySelector(this.sourceValue) === null) {
            await this.leave(url);
            return;
        }
        // The same record again (a property saved, a comment added): it stays where it was read,
        // with the focus on the field that changed, as a morphed page keeps its scroll.
        const same = this.url && new URL(url, window.location.href).pathname === new URL(this.url, window.location.href).pathname;
        const scroll = same && this.hasBodyTarget ? this.bodyTarget.scrollTop : 0;
        const focused = same && this.element.contains(document.activeElement) ? document.activeElement.id : '';
        this.fill(html);
        this.url = url;
        this.dirty = false;
        if (this.pushed) {
            history.replaceState(history.state, '', url);
        }
        const back = focused ? this.element.querySelector(`[id="${CSS.escape(focused)}"]`) : null;
        if (same && this.hasBodyTarget) {
            this.bodyTarget.scrollTop = scroll;
        }
        if (back && visible(back)) {
            back.focus({ preventScroll: true });
        } else {
            this.focusInitial();
        }
    }

    // Closes the host and visits a URL as a page of its own, after the host's history entry.
    async leave(url, action = 'replace') {
        this.dirty = false;
        this.navigating = true;
        this.close();
        await this.popHistory();
        const drive = document.body.dataset.turbo !== 'false';
        if (drive) {
            visit(url, { action });
        } else {
            action === 'replace' ? window.location.replace(url) : window.location.assign(url);
        }
    }

    // Open as page: the record's URL as the page, a step after the list in the history.
    openPage(event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.currentTarget;
        event.preventDefault();
        this.leave(link.href, 'advance');
    }

    open(opener = document.activeElement) {
        // A host taken out of the page never holds the layer: showModal() would throw, and the
        // layer would stay taken, so that no dialog or sheet opens again until a full reload.
        if (!this.element.isConnected || (current[this.layerValue] !== null && current[this.layerValue] !== this) || this.blocked()) {
            return false;
        }
        if (this.element.open) {
            return true;
        }
        current[this.layerValue] = this;
        this.afterClose = null;
        this.opener = opener instanceof HTMLElement ? opener : null;
        this.returnTo = null;
        this.dirty = false;
        this.navigating = false;
        this.hideDiscard();
        this.element.showModal();
        this.focusInitial();
        this.pushHistory();
        this.dispatch('opened');

        return true;
    }

    // A sheet never opens over a dialog.
    blocked() {
        return this.layerValue === 'sheet' && current.dialog !== null;
    }

    // Puts the dialog of a server answer into this host, which may be open already.
    fill(html) {
        const page = new DOMParser().parseFromString(html, 'text/html');
        const source = page.querySelector(this.sourceValue) || page.querySelector('dialog');
        if (source === null) {
            throw new Error('The answer holds no dialog.');
        }
        const own = ['id', 'open', 'data-controller', 'data-action', 'data-dialog-remote-value', 'data-dialog-failed-value', 'data-dialog-layer-value', 'data-dialog-link-value', 'data-dialog-source-value'];
        Array.from(this.element.attributes).filter((attribute) => !own.includes(attribute.name)).forEach((attribute) => this.element.removeAttribute(attribute.name));
        Array.from(source.attributes).filter((attribute) => !own.includes(attribute.name)).forEach((attribute) => this.element.setAttribute(attribute.name, attribute.value));
        this.element.replaceChildren(...Array.from(source.childNodes).map((node) => document.importNode(node, true)));
        if (this.element.dataset.dialogReload === '1') {
            // The server took a change and shows the dialog again: nothing typed is left to lose.
            this.reload = true;
            this.dirty = false;
        }
        // The sheet fits its width to what it shows now (a form with lines fills the panel).
        this.dispatch('filled');
    }

    focusInitial() {
        const shown = (selector) => Array.from(this.element.querySelectorAll(selector)).find(visible);
        const target = shown('[data-error-summary][autofocus]')
            || shown('[aria-invalid="true"]')
            || shown('[autofocus]')
            || (this.hasBodyTarget ? Array.from(this.bodyTarget.querySelectorAll(FIELDS)).find(visible) : null)
            || (this.hasTitleTarget ? this.titleTarget : shown(FOCUSABLE));
        if (target) {
            target.focus();
            if (target.matches('[aria-invalid="true"]')) {
                target.scrollIntoView({ block: 'center', inline: 'nearest' });
            }
        }
    }

    // Closing

    dismiss() {
        this.dirty ? this.showDiscard() : this.close();
    }

    // Resolves once the dialog is closed and its history entry is gone.
    close() {
        if (!this.element.open) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            this.afterClose = resolve;
            this.element.close();
        });
    }

    // Escape, or the Back gesture on Android. Chrome fires cancel only when the user did
    // something since the last prevented one (CloseWatcher): a second Escape in a row, or a
    // third after "Keep editing" by keyboard alone, closes the dialog without asking. A guard
    // can stop the first close request after typing; it cannot trap anyone, by design.
    cancelled(event) {
        if (event.target !== this.element) {
            return;
        }
        if (this.dirty) {
            event.preventDefault();
            this.showDiscard();
        }
    }

    async closed(event) {
        if (event && event.target !== this.element) {
            return;
        }
        if (current[this.layerValue] === this) {
            current[this.layerValue] = null;
        }
        this.dirty = false;
        this.hideDiscard();
        if (!this.navigating) {
            await this.popHistory();
            this.restoreFocus();
            if (this.remoteValue) {
                this.element.replaceChildren();
            }
        }
        if (this.reload) {
            this.reload = false;
            if (!this.navigating) {
                this.go(window.location.href);
            }
        }
        if (this.answer) {
            const answer = this.answer;
            this.answer = null;
            answer(this.accepted === true);
        }
        this.dispatch('closed');
        if (this.afterClose) {
            const afterClose = this.afterClose;
            this.afterClose = null;
            afterClose();
        }
    }

    restoreFocus() {
        let target = this.returnTo || this.opener;
        if (target && (!target.isConnected || !visible(target))) {
            const menu = target.closest('[role="menu"]');
            const button = menu ? document.getElementById(menu.getAttribute('aria-labelledby')) : null;
            const named = target.dataset.dialogReturn ? document.querySelector(target.dataset.dialogReturn) : null;
            target = (button && visible(button) && button) || named || null;
        }
        (target || document.getElementById('main') || document.body).focus({ preventScroll: false });
    }

    clicked(event) {
        const step = this.record && event.button === 0 && !event.defaultPrevented && !(event.metaKey || event.ctrlKey || event.shiftKey || event.altKey)
            && event.target instanceof Element ? event.target.closest('a[href]') : null;
        const area = step ? step.closest('[data-in-place]') : null;
        if (step && area && this.element.contains(area) && step.origin === window.location.origin && !step.hasAttribute('download') && !step.target) {
            event.preventDefault();
            this.swap(step.href);
            return;
        }
        const link = event.target instanceof Element ? event.target.closest('a[href^="#"]') : null;
        if (link && this.element.contains(link)) {
            // An error summary links to its fields: move there inside the dialog, leave the URL alone.
            const field = this.element.querySelector(`[id="${CSS.escape(link.getAttribute('href').slice(1))}"]`);
            if (field) {
                event.preventDefault();
                field.focus();
                field.scrollIntoView({ block: 'center', inline: 'nearest' });
            }
            return;
        }
        if (event.target !== this.element || !this.dismissableValue || this.dirty) {
            return;
        }
        const box = this.element.getBoundingClientRect();
        const inside = event.clientX >= box.left && event.clientX <= box.right && event.clientY >= box.top && event.clientY <= box.bottom;
        if (!inside) {
            this.close();
        }
    }

    // Unsaved changes

    changed(event) {
        // A field that saves itself (an upload sent on choosing) leaves nothing to lose.
        if (event.target instanceof Element && event.target.name && event.target.closest('form') && !event.target.closest('[data-dialog-untracked]')) {
            this.dirty = true;
        }
    }

    showDiscard() {
        if (!this.hasDiscardTarget) {
            this.close();
            return;
        }
        this.beforeDiscard = this.element.contains(document.activeElement) ? document.activeElement : null;
        this.actionsTarget.hidden = true;
        this.discardTarget.hidden = false;
        this.keepTarget.focus();
    }

    hideDiscard() {
        if (this.hasDiscardTarget) {
            this.discardTarget.hidden = true;
            this.actionsTarget.hidden = false;
        }
    }

    keepEditing() {
        this.hideDiscard();
        const back = this.beforeDiscard && this.beforeDiscard.isConnected && visible(this.beforeDiscard) ? this.beforeDiscard : null;
        back ? back.focus() : this.focusInitial();
    }

    discard() {
        this.dirty = false;
        this.close();
    }

    // Sending the form of a loaded dialog

    async submit(event) {
        const form = event.target;
        // A form's own controller may hold its submit back: confirm-submit until the answer, a second click on Save.
        if (!this.remoteValue || !(form instanceof HTMLFormElement) || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        if (this.submitting) {
            return;
        }
        this.submitting = true;
        this.element.setAttribute('aria-busy', 'true');
        const data = new FormData(form);
        if (event.submitter && event.submitter.name) {
            data.append(event.submitter.name, event.submitter.value);
        }
        try {
            const response = await fetch(form.action, { method: 'POST', body: data, headers: { [HEADER]: this.layerHeader(), Accept: 'application/json, text/html' }, credentials: 'same-origin' });
            if (isType(response, 'application/json') && response.ok) {
                await this.finish(await response.json());
            } else if (isType(response, 'text/html') && (response.ok || response.status === 422)) {
                this.fill(await response.text());
                this.focusInitial();
            } else {
                throw new Error(`The dialog answered ${response.status}.`);
            }
        } catch {
            this.fail();
        } finally {
            this.submitting = false;
            this.element.removeAttribute('aria-busy');
        }
    }

    async finish(result) {
        this.dirty = false;
        if (this.record && !result.event && result.location) {
            // Edit in place: the record shows what the server sends next (the detail again) in
            // the same host; the list under it takes the change once the host closes.
            this.reload = true;
            await this.swap(result.location);
            return;
        }
        if (result.event) {
            // The opener's page takes the result, the dialog closes over it.
            document.dispatchEvent(new CustomEvent(result.event, { detail: result.detail || {} }));
            const focus = this.opener && this.opener.dataset.dialogFocus;
            this.returnTo = focus ? document.querySelector(focus) : null;
            this.announce(result.message);
            this.close();
            return;
        }
        const then = this.opener && this.opener.dataset.dialogThen === 'follow' ? result.location : window.location.href;
        const sheet = current.sheet;
        if (this.layerValue === 'dialog' && sheet && sheet.record && sheet.element.open) {
            // A dialog over a record in the sheet (log time, a new subtask, sharing): the sheet
            // shows what comes next in place, and the list under it takes the change once it closes.
            await this.close();
            sheet.reload = true;
            await sheet.swap(then === window.location.href ? sheet.url : then);
            return;
        }
        this.navigating = true;
        this.close();
        await this.popHistory();
        this.go(then);
    }

    go(url) {
        const drive = document.body.dataset.turbo !== 'false';
        drive ? visit(url, { action: 'replace' }) : window.location.replace(url);
    }

    fail() {
        if (!this.hasBodyTarget) {
            return;
        }
        this.bodyTarget.querySelector('[data-dialog-failed]')?.remove();
        const alert = document.createElement('p');
        alert.setAttribute('role', 'alert');
        alert.setAttribute('tabindex', '-1');
        alert.dataset.dialogFailed = '';
        alert.className = 'm-0 mb-4 rounded-panel bg-badge-red px-4 py-3 text-small font-medium text-badge-red-text outline-none';
        alert.textContent = this.failedValue;
        this.bodyTarget.prepend(alert);
        alert.focus();
    }

    announce(message) {
        const status = document.getElementById('dialog-status');
        if (status && message) {
            status.textContent = '';
            requestAnimationFrame(() => { status.textContent = message; });
        }
    }

    // History: one entry on a phone, so Back closes the dialog

    pushHistory() {
        const record = this.record && this.url;
        if (!record && !window.matchMedia(PHONE).matches) {
            return;
        }
        // A record's own URL: a reload, a new tab or a bookmark is its page. Turbo's state stays,
        // so a Back onto this entry after leaving by another link restores the record's page.
        history.pushState({ ...(history.state || {}), foresiDialog: true }, '', record ? this.url : undefined);
        this.pushed = true;
        // While the entry is there, a step back out of it is the dialog's, not a Turbo
        // restoration visit, which would render the page again and lose the focus.
        this.pauseTurbo();
    }

    pauseTurbo() {
        const turbo = session && session.history;
        if (turbo && turbo.started) {
            turbo.stop();
            this.turboPaused = true;
        }
    }

    resumeTurbo() {
        if (this.turboPaused) {
            this.turboPaused = false;
            session.history.start();
        }
    }

    popHistory() {
        if (!this.pushed) {
            return Promise.resolve();
        }
        this.pushed = false;

        return new Promise((resolve) => {
            const done = () => {
                clearTimeout(timer);
                this.popping = null;
                popper = null;
                this.resumeTurbo();
                resolve();
            };
            const timer = setTimeout(done, HISTORY_WAIT);
            this.popping = done;
            popper = this;
            // The entry stays ahead as a forward step. Without Turbo's state it is no visit, and
            // the host steps over it if Forward lands there.
            history.replaceState({ foresiDialog: 'closed' }, '');
            history.back();
        });
    }

    popped(event) {
        if (this.popping) {
            this.popping();
            return;
        }
        if (popper !== null || (this.layerValue === 'sheet' && current.dialog !== null)) {
            // Another one steps back out of its own entry, or a dialog over this sheet takes Back.
            return;
        }
        if (this.remoteValue && this.layerValue === 'dialog' && event.state && event.state.foresiDialog === 'closed' && current.dialog === null) {
            // Forward onto the step a closed dialog left: go back to the page's own entry quietly.
            this.pauseTurbo();
            popper = this;
            this.popping = () => {
                this.popping = null;
                popper = null;
                this.resumeTurbo();
            };
            history.back();
            return;
        }
        if (!this.pushed || !this.element.open) {
            return;
        }
        this.pushed = false;
        this.resumeTurbo();
        if (this.dirty) {
            // Back asks like Escape does; the entry comes back for the next press.
            this.pushHistory();
            this.showDiscard();
        } else {
            this.close();
        }
    }

    // Turbo

    reset() {
        this.navigating = true;
        this.pushed = false;
        this.resumeTurbo();
        this.close();
        if (this.remoteValue) {
            this.element.replaceChildren();
        }
    }

    keepOpen(event) {
        if (event.target === this.element && event.detail.attributeName === 'open') {
            event.preventDefault();
        }
    }

    // Confirmation

    ask(event) {
        const { resolve, ...options } = event.detail || {};
        this.confirm(options).then((answer) => resolve && resolve(answer));
    }

    confirm({ title, text, accept, cancel, tone }) {
        return new Promise((resolve) => {
            if (current.dialog !== null) {
                // One dialog at a time: inside another one the browser asks.
                resolve(window.confirm(title));
                return;
            }
            this.titleTarget.textContent = title || '';
            this.textTarget.textContent = text || '';
            this.textTarget.hidden = !text;
            const danger = tone === 'danger';
            this.acceptTargets.forEach((button) => {
                button.hidden = (button.dataset.tone === 'danger') !== danger;
                button.textContent = accept || button.dataset.label;
            });
            this.rejectTarget.textContent = cancel || this.rejectTarget.dataset.label;
            this.accepted = false;
            this.answer = resolve;
            this.open(document.activeElement);
            // The safe answer has the focus (APG).
            this.rejectTarget.focus();
        });
    }

    answerYes() {
        this.accepted = true;
        this.close();
    }

    answerNo() {
        this.accepted = false;
        this.close();
    }
}
