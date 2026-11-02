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

// One dialog at a time, across every instance of this controller.
let current = null;

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
 *   event with the result). The same code runs on Turbo and on plain pages.
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
 */
export default class extends Controller {
    static targets = ['title', 'body', 'actions', 'discard', 'keep', 'text', 'accept', 'reject'];
    static values = { fields: Boolean, dismissable: Boolean, remote: Boolean, confirm: Boolean, failed: String };

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
            const auto = document.querySelector('a[data-dialog][data-dialog-autoopen]');
            if (auto) {
                auto.removeAttribute('data-dialog-autoopen');
                this.load(auto.href, auto);
            }
        }
    }

    disconnect() {
        if (current === this) {
            current = null;
        }
    }

    // Opening

    follow(event) {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        const link = event.target instanceof Element ? event.target.closest('a[data-dialog]') : null;
        if (link === null || this.element.contains(link)) {
            return;
        }
        event.preventDefault();
        this.load(link.href, link);
    }

    async load(url, opener) {
        if (current !== null || this.loading) {
            return;
        }
        this.loading = true;
        try {
            const response = await fetch(url, { headers: { [HEADER]: '1', Accept: 'text/html, application/json' }, credentials: 'same-origin' });
            if (response.ok && isType(response, 'application/json')) {
                // Nothing to ask (a trashed invoice, say): go where the server sends, its message waits there.
                const { location } = await response.json();
                this.go(location || url);
                return;
            }
            if (!response.ok || !isType(response, 'text/html')) {
                throw new Error(`The dialog answered ${response.status}.`);
            }
            this.fill(await response.text());
        } catch {
            // The page itself still works.
            window.location.assign(url);
            return;
        } finally {
            this.loading = false;
        }
        this.open(opener);
    }

    open(opener = document.activeElement) {
        if (current !== null && current !== this) {
            return false;
        }
        if (this.element.open) {
            return true;
        }
        current = this;
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

    // Puts the dialog of a server answer into this host, which may be open already.
    fill(html) {
        const page = new DOMParser().parseFromString(html, 'text/html');
        const source = page.querySelector('dialog#dialog-page') || page.querySelector('dialog');
        if (source === null) {
            throw new Error('The answer holds no dialog.');
        }
        const own = ['id', 'open', 'data-controller', 'data-action', 'data-dialog-remote-value', 'data-dialog-failed-value'];
        Array.from(this.element.attributes).filter((attribute) => !own.includes(attribute.name)).forEach((attribute) => this.element.removeAttribute(attribute.name));
        Array.from(source.attributes).filter((attribute) => !own.includes(attribute.name)).forEach((attribute) => this.element.setAttribute(attribute.name, attribute.value));
        this.element.replaceChildren(...Array.from(source.childNodes).map((node) => document.importNode(node, true)));
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
        if (current === this) {
            current = null;
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
        if (event.target instanceof Element && event.target.name && event.target.closest('form')) {
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
        if (!this.remoteValue || !(form instanceof HTMLFormElement)) {
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
            const response = await fetch(form.action, { method: 'POST', body: data, headers: { [HEADER]: '1', Accept: 'application/json, text/html' }, credentials: 'same-origin' });
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
        if (!window.matchMedia(PHONE).matches) {
            return;
        }
        history.pushState({ ...(history.state || {}), foresiDialog: true }, '');
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
                this.resumeTurbo();
                resolve();
            };
            const timer = setTimeout(done, HISTORY_WAIT);
            this.popping = done;
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
        if (this.remoteValue && event.state && event.state.foresiDialog === 'closed' && current === null) {
            // Forward onto the step a closed dialog left: go back to the page's own entry quietly.
            this.pauseTurbo();
            this.popping = () => {
                this.popping = null;
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
            if (current !== null) {
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
