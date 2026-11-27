<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Contract;

/**
 * The Ui slots of a project's page, filled by the modules that keep something
 * of a project, such as its activities. A provider answers the slot with
 * Foresi\Ui\Contract\Slot\SlotItem; a project without them shows none, so Work
 * renders the same whether those modules are installed or not. Lower weights
 * come first.
 *
 * NEW_FORM is the one slot of the dialog of a new project, before the project
 * exists: inside its form after the custom fields, such as a behaviour of a
 * field of the module's own. The item's template is included with its data and
 * the context, which holds "clientField" (string|null): the id of the client
 * combobox, which sends "combobox:pick" and "combobox:clear", or null where the
 * address book is not installed; an item without a template is left out.
 *
 * The slots of a project's page get the same context:
 *  - "projectId" (string): the project's id;
 *  - "clientId" (string|null): the id of the project's client, a contact of the address book, or null;
 *  - "return" (string): the local address of the project's page as shown, with its ?tab=, to come back to after a dialog.
 *
 * SECTIONS: tabs of the page after List and Board, which stay the default (?tab=<key>
 * shows the section in place of the tasks). The item's data holds "key" (string,
 * lowercase letters, digits and dashes), "label" (string, the tab's name) and either
 * "href" (a local address of a page fragment, loaded into
 * <turbo-frame id="project-section-<key>"> by a lazy frame: the response holds a
 * frame of the id the Turbo-Frame header names) or the item's template, included
 * with its data and the context. An item with neither, or without a key or a label,
 * is left out.
 *
 * HEADER_FACTS: short facts under the page header, next to the client line, such as
 * a budget. The item's template is included with its data and the context; an item
 * without a template is left out.
 *
 * HEADER_ACTIONS: actions of the header of the project's list and board, after a new
 * task, such as logging time. The item's template is included with its data and the
 * context, with "place" saying where: "buttons" for a button of the header, "phone"
 * for the same action as an item of the "⋯" menu on a phone, and "menu" for an item
 * of the "⋯" menu on every screen, such as billing; an item without a template is
 * left out.
 *
 * SETTINGS: cards of the project's settings page after its general settings, such
 * as the hourly rate of its time. The item's template is included with its data and
 * the context; an item without a template is left out. Only whom keeps the project's
 * settings gets this slot.
 */
final class ProjectSlots
{
    public const string SECTIONS = 'work.project.sections';

    public const string HEADER_FACTS = 'work.project.header_facts';

    public const string HEADER_ACTIONS = 'work.project.header_actions';

    public const string SETTINGS = 'work.project.settings';

    public const string NEW_FORM = 'work.project.new_form';

    /** The prefix of the id of the Turbo frame a section's fragment loads into. */
    public const string SECTION_FRAME = 'project-section-';

    private function __construct()
    {
    }
}
