<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Contract;

/**
 * The tabs of a contact after the address book's own, filled by the modules that keep something
 * of a contact, such as its deals. A provider answers the Ui slot SLOT with items whose data holds
 * "key", "label" and "href"; the context holds "contactId". Lower weights come first: the
 * address book's tabs take the weights 0 to 50, so a contributed tab starts at 100. A key the
 * address book uses itself is ignored, as is an item without the three values.
 *
 * The tab opens at the contact's address with ?tab=<key>. Its body is the module's own page
 * fragment at "href", loaded inside the contact detail by a lazy Turbo frame (id FRAME,
 * loading="lazy"): the firm tabs open module pages of their own and the side sheet holds one
 * record, so neither fits a body under the contact's header. The response to "href" holds
 * <turbo-frame id="contact-tab"> with the body; links in it open as whole pages unless they
 * name the frame (data-turbo-frame="contact-tab").
 */
final class ContactTabs
{
    public const string SLOT = 'party.contact_tabs';

    /** The id of the Turbo frame a contributed tab's body loads into. */
    public const string FRAME = 'contact-tab';
}
