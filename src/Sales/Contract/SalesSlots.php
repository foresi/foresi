<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Contract;

/**
 * The Ui slots of a deal's page, filled by the modules that keep something of
 * a deal, such as its documents and activities. A provider answers the slot
 * with Foresi\Ui\Contract\Slot\SlotItem; a deal without them shows none, so
 * Sales renders the same whether those modules are installed or not. Lower
 * weights come first.
 *
 * Both slots get the same context:
 *  - "dealId" (string): the deal's id;
 *  - "clientId" (string): the id of the deal's client, a contact of the address book;
 *  - "contactPersonIds" (list<string>): the deal's contact persons, in their order;
 *  - "return" (string): the local address of the deal's page, to come back to after a dialog.
 *
 * DEAL_ACTIONS: actions of the page header, in its "⋯" menu after Sales' own. The item's
 * data holds "label" (string) and "href" (string, a local address), and optionally "icon"
 * (an icon of the locked set), "dialog" (bool, true opens the address in a dialog over the
 * page), "key" (string, written as data-deal-action) and "prominent" (bool, true asks for
 * a button beside the primary action: the first prominent item by weight gets it, on a
 * phone it stays in the menu). An item without a label or an href is left out.
 *
 * DEAL_SECTIONS: tabs of the page under the lines, the active one shown (?tab=<key>, the
 * first by weight when none is asked for). The item's data holds "key" (string, lowercase
 * letters, digits and dashes), "label" (string, the tab's name) and either "href" (a
 * local address of a page fragment, loaded into <turbo-frame id="deal-section-<key>">
 * by a lazy frame: the response holds a frame of the id the Turbo-Frame header names)
 * or the item's template, included with its data and the context. An item with
 * neither, or without a key or a label, is left out.
 */
final class SalesSlots
{
    public const string DEAL_ACTIONS = 'sales.deal_actions';

    public const string DEAL_SECTIONS = 'sales.deal_sections';

    /** The prefix of the id of the Turbo frame a section's fragment loads into. */
    public const string SECTION_FRAME = 'deal-section-';

    private function __construct()
    {
    }
}
