/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const SPACES = /[\s   ]/g;

/**
 * A field for an amount or a quantity: groups thousands as you type and keeps the
 * caret among the same digits, and writes the number out in full on blur. Fixed
 * decimals with "decimals" set ("1 000,00"), trimmed without it ("1,5"). Separators
 * come from the language of the page. It reads pasted text like TypedNumber on the
 * server, which accepts all of this too, so the field works without JavaScript.
 * It changes how a number is written, never its value, and text that is not a number
 * stays as typed for the server to refuse.
 *
 *     <input data-controller="number-input" data-number-input-decimals-value="2"
 *            data-action="input->number-input#typed blur->number-input#finished">
 */
export default class extends Controller {
    static values = { decimals: { type: Number, default: -1 } };

    connect() {
        const parts = new Intl.NumberFormat(document.documentElement.lang).formatToParts(1234567.8);
        this.group = parts.find((part) => part.type === 'group')?.value ?? ' ';
        this.decimal = parts.find((part) => part.type === 'decimal')?.value ?? ',';
        this.finished();
    }

    typed(event) {
        const field = this.element;
        if (event.inputType === 'insertFromPaste') {
            const number = this.parse(field.value);
            if (number !== null) {
                field.value = this.written(number, false);
            }
            return;
        }
        const clean = (text) => this.clean(text);
        const caret = clean(field.value.slice(0, field.selectionStart)).length;
        const number = clean(field.value);
        if (!/^-?\d*(?:\.\d*)?$/.test(number)) {
            return;
        }
        field.value = this.written(number, false);
        this.placeCaret(caret);
    }

    finished() {
        const number = this.parse(this.element.value);
        if (number !== null && number !== '') {
            this.element.value = this.written(number, true);
        }
    }

    // What was typed, without grouping, with a decimal point; a dot counts as a decimal mark too.
    clean(text) {
        const groups = this.group === ',' ? text.replaceAll(',', '') : text;
        return groups.replace(SPACES, '').replaceAll(this.decimal, '.').replaceAll(',', '.');
    }

    // The rules of TypedNumber.
    parse(typed) {
        const text = typed.replace(SPACES, (space) => (space === '' ? '' : ' ')).trim();
        if (text === '') {
            return '';
        }
        if (text.includes(' ')) {
            return /^-?\d{1,3}(?: \d{3})+(?:[.,]\d*)?$/.test(text) ? this.plain(text.replaceAll(' ', '').replace(',', '.')) : null;
        }
        const comma = text.lastIndexOf(',');
        const dot = text.lastIndexOf('.');
        if (comma !== -1 && dot !== -1) {
            const [decimal, group] = comma > dot ? [',', '.'] : ['.', ','];
            const pattern = new RegExp(`^-?\\d{1,3}(?:\\${group}\\d{3})+\\${decimal}\\d*$`);
            return pattern.test(text) ? this.plain(text.replaceAll(group, '').replace(decimal, '.')) : null;
        }
        for (const separator of [',', '.']) {
            if (text.split(separator).length > 2) {
                return new RegExp(`^-?\\d{1,3}(?:\\${separator}\\d{3})+$`).test(text) ? text.replaceAll(separator, '') : null;
            }
        }
        if (comma !== -1 && this.group === ',' && /^-?\d{1,3},\d{3}$/.test(text)) {
            return text.replace(',', '');
        }
        return this.plain(text.replace(',', '.'));
    }

    plain(number) {
        return /^-?\d+(?:\.\d*)?$/.test(number) ? number : null;
    }

    written(number, complete) {
        const [, sign, whole, point, fraction] = /^(-?)(\d*)(\.?)(\d*)$/.exec(number);
        const grouped = whole.replace(/\B(?=(\d{3})+$)/g, this.group);
        const decimals = complete ? this.completed(fraction) : fraction;
        const mark = point !== '' && !complete || decimals !== '' ? this.decimal : '';
        return `${sign}${grouped}${mark}${decimals}`;
    }

    // Never rounds: more decimals than the field shows stay for the server to refuse.
    completed(fraction) {
        return this.decimalsValue < 0 ? fraction.replace(/0+$/, '') : fraction.padEnd(this.decimalsValue, '0');
    }

    // After the same number of characters that count as it stood before the regrouping.
    placeCaret(counted) {
        const field = this.element;
        let position = 0;
        for (let seen = 0; seen < counted && position < field.value.length; position += 1) {
            if (this.clean(field.value[position]) !== '') {
                seen += 1;
            }
        }
        field.setSelectionRange(position, position);
    }
}
