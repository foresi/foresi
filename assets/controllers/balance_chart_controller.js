/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const OFFSET = 12;

/**
 * The tooltip of Ui:BalanceChart: the money in, out and the closing balance of
 * the period under the pointer, next to it, with the period's band shaded. It
 * is an enhancement only: the table beside the chart holds the same figures
 * for everyone, so the tooltip is hidden from assistive technology.
 *
 *     <div data-controller="balance-chart">
 *         <div data-balance-chart-target="frame"><svg>
 *             <g data-balance-chart-target="period" data-label="Dec" data-in="…" data-out="…" data-closing="…" data-centre="120"
 *                data-action="pointerenter->balance-chart#show pointerleave->balance-chart#hide">…</g>
 *         </svg><div hidden data-balance-chart-target="tip">…</div></div>
 *     </div>
 */
export default class extends Controller {
    static targets = ['frame', 'period', 'tip', 'tipLabel', 'tipIn', 'tipOut', 'tipClosing'];

    connect() {
        this.tipTarget.setAttribute('aria-hidden', 'true');
    }

    show(event) {
        const period = event.currentTarget;
        this.shade(period);
        this.tipLabelTarget.textContent = period.dataset.label;
        this.tipInTarget.textContent = period.dataset.in;
        this.tipOutTarget.textContent = period.dataset.out;
        this.tipClosingTarget.textContent = period.dataset.closing;
        this.tipClosingTarget.classList.toggle('text-badge-red-text', 'below' in period.dataset);
        this.tipTarget.hidden = false;
        this.place(Number(period.dataset.centre));
    }

    hide() {
        this.shade(null);
        this.tipTarget.hidden = true;
    }

    disconnect() {
        this.hide();
    }

    shade(current) {
        for (const period of this.periodTargets) {
            const band = period.querySelector('[data-balance-chart-band]');
            band?.setAttribute('fill', period === current ? 'var(--color-head)' : 'transparent');
        }
    }

    /** Puts the tip beside the period's centre, on the side with room for it. */
    place(centre) {
        const svg = this.frameTarget.querySelector('svg');
        const scale = svg.clientWidth / svg.viewBox.baseVal.width;
        const x = centre * scale - this.frameTarget.scrollLeft;
        const width = this.tipTarget.offsetWidth;
        const room = this.frameTarget.clientWidth;
        const left = x + OFFSET + width <= room ? x + OFFSET : Math.max(0, x - OFFSET - width);
        this.tipTarget.style.left = `${left}px`;
    }
}
