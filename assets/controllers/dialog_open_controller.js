/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */
import { Controller } from '@hotwired/stimulus';

/**
 * Opens a Ui:Dialog or Ui:Sheet of the page named by its id, from a button or a menu
 * item that is not next to it, such as "Save as new view" in a list's More views menu:
 * data-action="dialog-open#open" data-dialog-open-id-param="<id>".
 */
export default class extends Controller {
    open(event) {
        const dialog = document.getElementById(event.params.id);
        const host = dialog && this.application.getControllerForElementAndIdentifier(dialog, 'dialog');
        if (host) {
            event.preventDefault();
            host.open(event.currentTarget);
        }
    }
}
