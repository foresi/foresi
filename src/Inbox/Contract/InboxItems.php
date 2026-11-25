<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Contract;

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
}
