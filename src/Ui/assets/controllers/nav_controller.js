/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

const PERMANENT = ['rail', 'sidebar'];
const STATE = ['aria-current', 'data-shown', 'value'];

/**
 * The rail and the sidebar are data-turbo-permanent: Turbo keeps them across
 * visits, so they do not redraw and keep their scroll. Before the next page is
 * shown, this copies the state its server render holds (the current area and
 * section, the counts, the unread dot) onto the kept elements. When the next page
 * has other items, another firm or another language, the kept elements take its
 * content whole instead.
 */
export default class extends Controller {
    connect() {
        this.onBeforeRender = (event) => this.sync(event.detail.newBody);
        document.addEventListener('turbo:before-render', this.onBeforeRender);
    }

    disconnect() {
        document.removeEventListener('turbo:before-render', this.onBeforeRender);
    }

    sync(newBody) {
        for (const id of PERMANENT) {
            const kept = document.getElementById(id);
            const next = newBody.querySelector(`#${id}`);
            if (!kept || !next) {
                continue;
            }
            const keptNodes = [...kept.querySelectorAll('[data-nav-sync]')];
            const nextNodes = [...next.querySelectorAll('[data-nav-sync]')];
            if (kept.dataset.navKey !== next.dataset.navKey || shape(keptNodes) !== shape(nextNodes)) {
                kept.replaceChildren(...next.childNodes);
                kept.dataset.navKey = next.dataset.navKey;
                continue;
            }
            keptNodes.forEach((node, index) => copyState(nextNodes[index], node));
        }
    }
}

function shape(nodes) {
    return nodes.map((node) => `${node.tagName}:${node.getAttribute('href') ?? ''}:${node.dataset.navArea ?? ''}`).join('|');
}

function copyState(from, to) {
    for (const name of STATE) {
        const value = from.getAttribute(name);
        if (value === null) {
            to.removeAttribute(name);
        } else if (to.getAttribute(name) !== value) {
            to.setAttribute(name, value);
        }
    }
    if (to.tagName === 'INPUT') {
        to.value = from.value;
    }
    if (to.hasAttribute('data-nav-count') && to.textContent !== from.textContent) {
        to.textContent = from.textContent;
    }
}
