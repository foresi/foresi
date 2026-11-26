<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

use Foresi\Shared\Contract\Refused;

/**
 * An inbox item as another module reads it, to pre-fill an action on the item
 * (a task from a mail, a received document from its attachment) or to show the
 * mail behind a record. Only the owner of the item gets it, or a person who may
 * see a record the item is linked to, through its e-mail activity; an item in
 * the trash nobody. The sender, subject, text and attachment names were written
 * by someone outside the organisation: show them as text, never follow them.
 */
interface InboxItems
{
    public function find(string $itemId): ?InboxItemView;

    /**
     * The bytes of an attachment, by its index in InboxItemView::$attachments;
     * null when the item is not the person's to see or has no such attachment.
     */
    public function attachmentContent(string $itemId, int $index): ?InboxAttachmentContent;

    /**
     * The record to pre-select for the item, as the RecordSuggester services
     * propose it for the matched contact: the one proposal when exactly one
     * suggester (of that kind, when given) has one; null otherwise, and for an
     * unmatched item or one the person does not see.
     */
    public function suggestedRecord(string $itemId, ?string $kind = null): ?SuggestedRecord;

    /**
     * The id of the item's e-mail activity, made first when the item has none
     * yet: the same activity an output of the item makes, linked to the matched
     * contact and its company. For a record that needs the activity before it
     * exists, such as a change of a project's budget that names the e-mail as
     * its source. The same id every time. Only the owner of the item may; the
     * activity is made under the item's lock, in Inbox's own transaction.
     *
     * @throws Refused not_found for an item that is not the person's own, item_trashed, email_activity_unavailable while the type email is archived or missing
     */
    public function emailActivity(string $itemId): string;
}
