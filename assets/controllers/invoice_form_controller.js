/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const RECOUNT_DELAY = 250;
const MAX_SYMBOL_DIGITS = 10;

/**
 * The live part of the invoice form, which works without it through the server's
 * re-render actions. Amounts are never calculated here: every change asks the
 * server, which uses the calculator of the save. Rows are added, removed and
 * reordered here, with the names renumbered, so the server reads them in the order
 * on screen. Enter in a field never issues a document; in the last cell of a row it
 * adds a line.
 */
export default class extends Controller {
    static targets = [
        'line', 'lines', 'lineTemplate', 'totals', 'summaries', 'net', 'vat', 'rounding', 'roundingRow', 'payable',
        'stale', 'addLine', 'lineLimit', 'dueDateSet', 'numberPeek', 'series', 'variableSymbol', 'issueDate', 'taxPointDate', 'dueDate',
        'errors', 'paymentMethod', 'bankRow', 'language', 'customerBox', 'summary', 'contactLink', 'notice', 'noticeText', 'noScript',
    ];

    static values = {
        recountUrl: String, numberUrl: String, contactUrl: String, firm: String, editing: Boolean, payer: Boolean,
        issuerCountry: String, currency: String, dueDays: Number, titleTemplate: String, noticeTemplate: String,
        leave: String, registrationLabel: String, vatLabel: String, unsaved: Boolean,
        maxLines: Number, newLineVat: String, dueDateSet: Boolean,
    };

    connect() {
        // A form the server sent back still holds what nobody saved.
        this.dirty = this.unsavedValue;
        this.leaving = false;
        this.noScriptTargets.forEach((element) => { element.hidden = true; });
        this.taxPointFollows = this.taxPointDateTarget.value === this.issueDateTarget.value;
        this.dueDateFollows = !this.dueDateSetValue;
        this.symbolFollows = this.hasVariableSymbolTarget && this.variableSymbolTarget.value === this.symbolOf(this.numberPeekTarget.value);
        this.originalSeries = this.hasSeriesTarget ? this.seriesTarget.value : '';
        this.originalNumber = this.numberPeekTarget.value;
        if (this.dirty) {
            this.guardBack();
        }
        if (this.hasErrorsTarget) {
            // After the form-errors controller has placed the first invalid field.
            requestAnimationFrame(() => requestAnimationFrame(() => this.clearOfErrors(document.activeElement)));
        }
    }

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    // Changes

    changed() {
        this.dirty = true;
        this.guardBack();
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.recount(), RECOUNT_DELAY);
    }

    async recount() {
        this.request?.abort();
        this.request = new AbortController();
        try {
            const response = await fetch(this.recountUrlValue, {
                method: 'POST',
                body: new FormData(this.element),
                headers: { Accept: 'application/json' },
                signal: this.request.signal,
            });
            // A row that cannot be counted yet keeps the last figures, marked as old; the save names the mistake.
            this.staleTarget.hidden = response.ok;
            if (response.ok) {
                this.show(await response.json());
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.staleTarget.hidden = false;
            }
        }
    }

    show(figures) {
        this.lineTargets.forEach((row, index) => {
            row.querySelector('[data-line-total]').textContent = figures.lines[index]?.total ?? '';
        });
        if (this.hasNetTarget) {
            this.netTarget.textContent = figures.net;
            this.vatTarget.textContent = figures.vat ?? '';
        }
        this.roundingTarget.textContent = figures.rounding ?? '';
        this.roundingRowTarget.classList.toggle('hidden', figures.rounding === null);
        this.payableTarget.textContent = figures.payable;
        this.summariesTarget.classList.toggle('hidden', figures.summaries.length === 0);
        this.summariesTarget.replaceChildren(...figures.summaries.map((summary) => this.summaryRow(summary)));
    }

    summaryRow(summary) {
        const row = document.createElement('div');
        row.className = 'flex justify-between gap-4';
        const label = document.createElement('span');
        label.textContent = summary.label;
        const amount = document.createElement('span');
        amount.className = 'num';
        amount.textContent = summary.vat === '' ? summary.base : `${summary.base} · ${summary.vat}`;
        row.append(label, amount);
        return row;
    }

    // Dates

    issueDateChanged() {
        const issued = this.issueDateTarget.value;
        if (issued === '') {
            return;
        }
        if (this.taxPointFollows) {
            this.taxPointDateTarget.value = issued;
        }
        if (this.dueDateFollows) {
            this.dueDateTarget.value = this.daysAfter(issued, this.dueDaysValue);
        }
        if (!this.editingValue) {
            this.refreshNumber();
        }
    }

    taxPointTyped() {
        this.taxPointFollows = false;
    }

    dueDateTyped() {
        this.dueDateFollows = false;
        this.dueDateSetTarget.value = '1';
    }

    daysAfter(date, days) {
        const [year, month, day] = date.split('-').map(Number);
        return new Date(Date.UTC(year, month - 1, day + days)).toISOString().slice(0, 10);
    }

    // Number and variable symbol

    seriesChanged() {
        if (this.editingValue && this.seriesTarget.value === this.originalSeries) {
            this.showNumber(this.originalNumber);
            return;
        }
        this.refreshNumber();
    }

    async refreshNumber() {
        if (!this.hasSeriesTarget) {
            return;
        }
        const url = new URL(this.numberUrlValue, window.location.origin);
        url.searchParams.set('date', this.issueDateTarget.value);
        url.searchParams.set('series', this.seriesTarget.value);
        url.searchParams.set('firm', this.firmValue);
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (response.ok) {
            this.showNumber((await response.json()).number);
        }
    }

    // The pencil sits in the page header, outside this element.
    get numberField() {
        return document.getElementById('number');
    }

    outsideInput(event) {
        if (event.target === this.numberField) {
            this.numberTyped();
            this.changed();
        }
    }

    // A number typed by hand stays; only the preview moves under it.
    showNumber(number) {
        const field = this.numberField;
        const typed = field !== null && field.value !== this.numberPeekTarget.value && field.value !== '';
        this.numberPeekTarget.value = number;
        if (typed) {
            return;
        }
        if (field !== null) {
            field.value = number;
        }
        this.showTitle(number);
        this.followSymbol(number);
    }

    numberTyped() {
        const number = this.numberField.value.trim() || this.numberPeekTarget.value;
        this.showTitle(number);
        this.followSymbol(number);
    }

    showTitle(number) {
        const title = this.titleTemplateValue.replace('__NUMBER__', number);
        const heading = document.querySelector('main h1');
        heading.textContent = title;
        heading.title = title;
    }

    symbolTyped() {
        this.symbolFollows = false;
    }

    followSymbol(number) {
        if (this.symbolFollows) {
            this.variableSymbolTarget.value = this.symbolOf(number);
        }
    }

    // The rule of VariableSymbol on the server; the server derives it again on save.
    symbolOf(number) {
        const digits = number.replace(/\D/g, '');
        return digits.length > MAX_SYMBOL_DIGITS ? '' : digits;
    }

    // Customer and payment

    firmChanged() {
        this.leaving = true;
        this.element.requestSubmit(this.element.querySelector('button[name="_action"][value="refresh"]'));
    }

    customerPicked(event) {
        const customer = event.detail;
        this.field('customer[name]').value = customer.name;
        this.field('customer[registrationNumber]').value = customer.registrationNumber ?? '';
        this.field('customer[vatId]').value = customer.vatId ?? '';
        this.field('customer[street]').value = customer.billing.street;
        this.field('customer[city]').value = customer.billing.city;
        this.field('customer[postalCode]').value = customer.billing.postalCode;
        this.field('customer[countryCode]').value = customer.billing.countryCode;
        this.field('customer[email]').value = customer.email ?? '';
        this.field('customer[phone]').value = '';
        this.field('customer[web]').value = '';
        this.field('customer[registerEntry]').value = '';
        this.customerBoxTarget.classList.remove('hidden');
        this.summaryTarget.textContent = this.summaryOf(customer);
        this.contactLinkTarget.href = this.contactUrlValue.replace(/[0-9a-f-]{36}/, customer.id);

        this.dueDaysValue = customer.dueDays;
        if (this.dueDateFollows && this.issueDateTarget.value !== '') {
            this.dueDateTarget.value = this.daysAfter(this.issueDateTarget.value, customer.dueDays);
        }
        this.paymentMethodTarget.value = customer.paymentMethod;
        this.paymentChanged();
        this.languageTarget.value = customer.language;
        if (this.payerValue) {
            this.newLineVatValue = customer.reverseCharge ? 'reverse_charge' : 'base';
            this.vatCells().forEach((cell) => {
                if (customer.reverseCharge) {
                    cell.value = 'reverse_charge';
                } else if (cell.value === 'reverse_charge') {
                    cell.value = 'base';
                }
            });
        }
        this.showSupplyCodes();
        const foreign = customer.currency !== this.currencyValue;
        this.noticeTarget.hidden = !foreign;
        this.noticeTextTarget.textContent = foreign ? this.noticeTemplateValue.replace('__CURRENCY__', customer.currency) : '';
        this.changed();
    }

    customerCleared() {
        this.customerBoxTarget.classList.add('hidden');
        this.noticeTarget.hidden = true;
    }

    summaryOf(customer) {
        const parts = [];
        if (customer.registrationNumber) {
            parts.push(`${this.registrationLabelValue} ${customer.registrationNumber}`);
        }
        if (this.payerValue && customer.vatId) {
            parts.push(`${this.vatLabelValue} ${customer.vatId}`);
        }
        const seat = [customer.billing.street, [customer.billing.postalCode, customer.billing.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');
        return [...parts, seat].join(' · ');
    }

    paymentChanged() {
        this.bankRowTarget.classList.toggle('hidden', this.paymentMethodTarget.value !== 'bank_transfer');
    }

    field(name) {
        return this.element.querySelector(`[name="${name}"]`);
    }

    // Rows

    vatChanged() {
        this.showSupplyCodes();
    }

    vatCells() {
        return Array.from(this.element.querySelectorAll('select[data-cell="vat"]'));
    }

    // The code belongs to a customer of the issuer's country; a hidden code must not stay behind.
    showSupplyCodes() {
        const vatId = this.field('customer[vatId]').value.trim().toUpperCase();
        const domestic = vatId === '' || vatId.startsWith(this.issuerCountryValue);
        this.lineTargets.forEach((row) => {
            const box = row.querySelector('[data-supply-code]');
            if (box !== null) {
                box.hidden = !(domestic && row.querySelector('select[data-cell="vat"]').value === 'reverse_charge');
                if (box.hidden) {
                    box.querySelector('select').value = '';
                }
            }
        });
    }

    newRow() {
        const row = this.lineTemplateTarget.content.firstElementChild.cloneNode(true);
        const vat = row.querySelector('select[data-cell="vat"]');
        if (vat !== null) {
            vat.value = this.newLineVatValue;
        }
        this.linesTarget.append(row);
        return row;
    }

    addLine() {
        if (this.lineTargets.length >= this.maxLinesValue) {
            return;
        }
        const row = this.newRow();
        this.renumber();
        row.querySelector('input[data-cell="text"]').focus();
        this.changed();
    }

    removeLine(event) {
        const row = event.currentTarget.closest('tr');
        const index = this.lineTargets.indexOf(row);
        if (this.lineTargets.length === 1) {
            this.newRow();
        }
        row.remove();
        this.renumber();
        // The row that took its place, or the new last one.
        const next = this.lineTargets[Math.min(index, this.lineTargets.length - 1)];
        next.querySelector('input[data-cell="text"]').focus();
        this.changed();
    }

    moveUp(event) {
        this.move(event.currentTarget, -1);
    }

    moveDown(event) {
        this.move(event.currentTarget, 1);
    }

    move(button, step) {
        const row = button.closest('tr');
        const neighbour = step < 0 ? row.previousElementSibling : row.nextElementSibling;
        if (neighbour === null) {
            return;
        }
        if (step < 0) {
            neighbour.before(row);
        } else {
            neighbour.after(row);
        }
        this.renumber();
        button.focus();
        this.changed();
    }

    // Dragging: a row can be dragged only from its grip, and only while the button is down.

    armDrag(event) {
        event.currentTarget.closest('tr').draggable = true;
    }

    disarmDrag() {
        if (this.dragged === undefined || this.dragged === null) {
            this.lineTargets.forEach((row) => { row.draggable = false; });
        }
    }

    dragStart(event) {
        this.dragged = event.target.closest('tr');
    }

    dragOver(event) {
        if (!this.dragged) {
            return;
        }
        event.preventDefault();
        const target = event.target.closest('tr');
        if (target !== null && target !== this.dragged) {
            const below = event.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
            target[below ? 'after' : 'before'](this.dragged);
        }
    }

    dragDrop() {
        // The row is in place already; dragend tidies up.
    }

    dragEnd() {
        if (!this.dragged) {
            return;
        }
        this.dragged.draggable = false;
        this.dragged = null;
        this.renumber();
        this.changed();
    }

    // Names, ids and buttons follow the order on screen.
    renumber() {
        this.lineTargets.forEach((row, index) => {
            row.querySelectorAll('[name], [id], [for], [aria-describedby], button[value]').forEach((element) => {
                for (const attribute of ['name', 'id', 'for', 'aria-describedby']) {
                    if (element.hasAttribute(attribute)) {
                        element.setAttribute(attribute, element.getAttribute(attribute).replace(/lines\[[^\]]+\]/, `lines[${index}]`).replace(/lines-[^-]+-/, `lines-${index}-`));
                    }
                }
                if (element.matches('button[value]')) {
                    element.value = element.value.replace(/:.*$/, `:${index}`);
                }
            });
        });
        this.showSupplyCodes();
        const full = this.lineTargets.length >= this.maxLinesValue;
        this.addLineTarget.disabled = full;
        this.lineLimitTarget.hidden = !full;
    }

    // Errors

    closeErrors() {
        this.errorsTarget.remove();
        (this.element.querySelector('[aria-invalid="true"]') ?? document.getElementById('invoice-save')).focus();
    }

    goToField(event) {
        const field = document.getElementById(event.currentTarget.hash.slice(1));
        if (field === null) {
            return;
        }
        for (let box = field.closest('details'); box !== null; box = box.parentElement.closest('details')) {
            box.open = true;
        }
        field.focus({ preventScroll: true });
        field.scrollIntoView({ block: 'center', inline: 'nearest' });
        this.clearOfErrors(field);
    }

    // The list floats over the form, so a field it names must end up above it.
    clearOfErrors(field) {
        if (!this.hasErrorsTarget || !(field instanceof HTMLElement)) {
            return;
        }
        const overlap = field.getBoundingClientRect().bottom + 8 - this.errorsTarget.getBoundingClientRect().top;
        if (overlap > 0) {
            window.scrollBy({ top: overlap, behavior: 'instant' });
        }
    }

    // Enter

    enter(event) {
        const field = event.target;
        if (field.form !== this.element || !field.matches('input, select') || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        const row = field.closest('tr');
        if (row !== null && field.dataset.cell === 'discount' && row === this.lineTargets.at(-1)) {
            this.addLine();
        }
    }

    // Leaving

    submitted() {
        this.leaving = true;
        this.dirty = false;
        clearTimeout(this.timer);
    }

    beforeUnload(event) {
        if (this.dirty && !this.leaving) {
            event.preventDefault();
            event.returnValue = '';
        }
    }

    // Back leaves a page without beforeunload when Turbo restores it, so a changed form
    // stands on an extra history entry: the same page, marked. Leaving that entry asks;
    // this runs before Turbo, which then sees nothing of a Back that stayed.
    guardBack() {
        if (this.guarded || history.state?.invoiceFormGuard) {
            this.guarded = true;
            return;
        }
        history.pushState({ ...history.state, invoiceFormGuard: true }, '', window.location.href);
        this.guarded = true;
    }

    popped(event) {
        if (!this.guarded || event.state?.invoiceFormGuard) {
            return;
        }
        event.stopImmediatePropagation();
        if (this.dirty && !this.leaving && !window.confirm(this.leaveValue)) {
            history.pushState({ ...history.state, invoiceFormGuard: true }, '', window.location.href);
            return;
        }
        this.guarded = false;
        this.dirty = false;
        history.back();
    }

    beforeVisit(event) {
        if (this.dirty && !this.leaving && !window.confirm(this.leaveValue)) {
            event.preventDefault();
        }
    }
}
