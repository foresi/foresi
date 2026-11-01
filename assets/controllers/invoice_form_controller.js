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
        'number', 'numberPeek', 'numberBox', 'series', 'variableSymbol', 'issueDate', 'taxPointDate', 'dueDate',
        'errors', 'paymentMethod', 'bankRow', 'language', 'customerBox', 'summary', 'contactLink', 'notice', 'noticeText', 'noScript',
    ];

    static values = {
        recountUrl: String, numberUrl: String, contactUrl: String, firm: String, editing: Boolean, payer: Boolean,
        issuerCountry: String, currency: String, dueDays: Number, titleTemplate: String, noticeTemplate: String,
        leave: String, registrationLabel: String, vatLabel: String, unsaved: Boolean,
    };

    connect() {
        // A form the server sent back still holds what nobody saved.
        this.dirty = this.unsavedValue;
        this.leaving = false;
        this.noScriptTargets.forEach((element) => { element.hidden = true; });
        this.taxPointFollows = this.taxPointDateTarget.value === this.issueDateTarget.value;
        this.dueDateFollows = true;
        this.symbolFollows = this.hasVariableSymbolTarget && this.variableSymbolTarget.value === this.symbolOf(this.numberPeekTarget.value);
        this.originalSeries = this.hasSeriesTarget ? this.seriesTarget.value : '';
        this.originalNumber = this.numberPeekTarget.value;
    }

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    // Changes

    changed() {
        this.dirty = true;
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
            // A row that cannot be counted yet keeps the last figures; the save names the mistake.
            if (response.ok) {
                this.show(await response.json());
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                throw error;
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

    // A number typed by hand stays; only the preview moves under it.
    showNumber(number) {
        const typed = this.hasNumberTarget && this.numberTarget.value !== this.numberPeekTarget.value && this.numberTarget.value !== '';
        this.numberPeekTarget.value = number;
        if (typed) {
            return;
        }
        if (this.hasNumberTarget) {
            this.numberTarget.value = number;
        }
        this.showTitle(number);
        this.followSymbol(number);
    }

    numberTyped() {
        const number = this.numberTarget.value.trim() || this.numberPeekTarget.value;
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

    // The code belongs to a customer of the issuer's country; another country has none.
    showSupplyCodes() {
        const vatId = this.field('customer[vatId]').value.trim().toUpperCase();
        const domestic = vatId === '' || vatId.startsWith(this.issuerCountryValue);
        this.lineTargets.forEach((row) => {
            const box = row.querySelector('[data-supply-code]');
            if (box !== null) {
                box.hidden = !(domestic && row.querySelector('select[data-cell="vat"]').value === 'reverse_charge');
            }
        });
    }

    addLine() {
        const row = this.lineTemplateTarget.content.firstElementChild.cloneNode(true);
        this.linesTarget.append(row);
        this.renumber();
        row.querySelector('input[data-cell="text"]').focus();
        this.changed();
    }

    removeLine(event) {
        const row = event.currentTarget.closest('tr');
        if (this.lineTargets.length === 1) {
            this.linesTarget.append(this.lineTemplateTarget.content.firstElementChild.cloneNode(true));
        }
        row.remove();
        this.renumber();
        this.lineTargets[0].querySelector('input[data-cell="text"]').focus();
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

    armDrag(event) {
        const row = event.currentTarget.closest('tr');
        row.draggable = true;
        row.addEventListener('dragstart', () => { this.dragged = row; }, { once: true });
        row.addEventListener('dragend', () => {
            row.draggable = false;
            this.dragged = null;
            this.linesTarget.removeEventListener('dragover', this.dragOver);
            this.renumber();
            this.changed();
        }, { once: true });
        this.dragOver = (over) => {
            over.preventDefault();
            const target = over.target.closest('tr');
            if (target !== null && target !== this.dragged && target.parentElement === this.linesTarget) {
                const below = over.clientY > target.getBoundingClientRect().top + target.offsetHeight / 2;
                target[below ? 'after' : 'before'](this.dragged);
            }
        };
        this.linesTarget.addEventListener('dragover', this.dragOver);
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
    }

    closeErrors() {
        this.errorsTarget.remove();
    }

    // Enter

    enter(event) {
        const field = event.target;
        if (field.form !== this.element || !field.matches('input, select') || event.defaultPrevented) {
            return;
        }
        event.preventDefault();
        const row = field.closest('tr');
        if (row !== null && field.dataset.cell === 'discount' && row === this.lineTargets[this.lineTargets.length - 1]) {
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

    beforeVisit(event) {
        if (this.dirty && !this.leaving && !window.confirm(this.leaveValue)) {
            event.preventDefault();
        }
    }
}
