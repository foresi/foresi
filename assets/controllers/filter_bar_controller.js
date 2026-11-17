/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Ui:FilterBar: "More filters" and, on a phone, "Filters (n)" open the bar's
 * side sheet, which sends its fields with one Apply. The chips are menus of
 * links and need nothing more.
 */
export default class extends Controller {
    static outlets = ['dialog'];

    open(event) {
        if (this.hasDialogOutlet) {
            this.dialogOutlet.open(event.currentTarget);
        }
    }
}
