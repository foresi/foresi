<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Inbox\Contract\InboxAttachmentContent;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\RecordSuggester;
use Foresi\Inbox\Contract\SuggestedRecord;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\UnknownMailMessage;

/**
 * The contract other modules read inbox items through. An item is seen by its
 * owner, and by an owner or member of the organisation who sees a record the
 * item is linked to, through the item's e-mail activity under Activities' own
 * rule; an item in the trash by nobody.
 */
final readonly class ItemsForOtherModules implements InboxItems
{
    /**
     * @param iterable<RecordSuggester> $suggesters
     */
    public function __construct(
        private ItemStore $store,
        private ItemReads $reads,
        private Messages $messages,
        private LinkableRecords $records,
        private AuditActor $actor,
        private StaffCheck $staff,
        private InboxAccess $access,
        private InboxTransaction $transaction,
        private EmailActivity $emailActivity,
        private iterable $suggesters,
    ) {
    }

    public function find(string $itemId): ?InboxItemView
    {
        $item = $this->visible($itemId);
        if (!$item instanceof InboxItem) {
            return null;
        }
        $owner = $item->isOwnedBy($this->actor->userId());

        return $this->reads->detail($item, $owner)->view($owner);
    }

    public function attachmentContent(string $itemId, int $index): ?InboxAttachmentContent
    {
        $item = $this->visible($itemId);
        if (!$item instanceof InboxItem) {
            return null;
        }
        try {
            $content = $this->messages->attachment($item->mailMessageId(), $index);
        } catch (UnknownMailMessage) {
            return null;
        }

        return new InboxAttachmentContent($content->name, $content->contentType, $content->bytes);
    }

    public function suggestedRecord(string $itemId, ?string $kind = null): ?SuggestedRecord
    {
        $item = $this->visible($itemId);
        $contactId = $item?->contactId();
        if (!$item instanceof InboxItem || null === $contactId) {
            return null;
        }
        $suggested = [];
        foreach ($this->suggesters as $suggester) {
            if (null !== $kind && $suggester->kind() !== $kind) {
                continue;
            }
            $record = $suggester->suggest($contactId, $item->companyId());
            if ($record instanceof SuggestedRecord) {
                $suggested[] = $record;
            }
        }

        return 1 === \count($suggested) ? $suggested[0] : null;
    }

    public function emailActivity(string $itemId): string
    {
        $item = $this->access->ownOrTrashed($itemId);
        if ($item->isTrashed()) {
            throw InboxRefused::itemTrashed();
        }
        if (null === $item->emailActivityId() && !$this->emailActivity->canLink($item)) {
            throw InboxRefused::emailActivityUnavailable();
        }

        return $this->transaction->run(function () use ($itemId): string {
            // Read again under the lock: an action at the same time may have made the activity.
            $item = $this->access->own($itemId);
            $this->store->lock($item);
            if (null === $item->emailActivityId()) {
                // No ActivityTarget offers the kind "email", so nothing but the contacts is linked, as on a first output.
                $this->emailActivity->link($item, EmailActivity::TYPE, $item->id());
            }

            return $item->emailActivityId() ?? throw InboxRefused::emailActivityUnavailable();
        });
    }

    private function visible(string $itemId): ?InboxItem
    {
        $userId = $this->actor->userId();
        $item = $this->store->item($itemId);
        if (null === $userId || !$item instanceof InboxItem || $item->isTrashed() || !$this->staff->isStaff()) {
            return null;
        }
        if ($item->isOwnedBy($userId)) {
            return $item;
        }
        $activityId = $item->emailActivityId();

        return null !== $activityId && $this->records->seesActivity($activityId) ? $item : null;
    }
}
