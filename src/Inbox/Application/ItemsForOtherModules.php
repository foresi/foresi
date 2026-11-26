<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Application;

use Foresi\Activities\Contract\LinkableRecords;
use Foresi\Audit\Application\AuditActor;
use Foresi\Identity\Contract\StaffCheck;
use Foresi\Inbox\Contract\InboxAttachment;
use Foresi\Inbox\Contract\InboxAttachmentContent;
use Foresi\Inbox\Contract\InboxItems;
use Foresi\Inbox\Contract\InboxItemView;
use Foresi\Inbox\Contract\InboxOutput;
use Foresi\Inbox\Contract\RecordSuggester;
use Foresi\Inbox\Contract\SuggestedRecord;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Mail\Contract\MailAttachment;
use Foresi\Mail\Contract\Messages;
use Foresi\Mail\Contract\SenderCheck;
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
        $detail = $this->reads->detail($item, $owner);
        // Another person learns nothing of the owner's mailbox: not the thread, nor records they do not see.
        $outputs = $owner ? $detail->outputs : array_values(array_filter($detail->outputs, static fn (OutputView $output): bool => $output->available));

        return new InboxItemView(
            $item->id(),
            $item->ownerUserId(),
            $item->state()->value,
            $item->receivedAt(),
            $detail->message?->from,
            $item->originalFrom(),
            $detail->message->verdict ?? SenderCheck::UNKNOWN,
            $item->isForwarded(),
            $item->contactId(),
            $item->companyId(),
            $detail->message->subject ?? '',
            $detail->text,
            array_map(static fn (MailAttachment $attachment): InboxAttachment => new InboxAttachment($attachment->index, $attachment->name, $attachment->contentType, $attachment->size), $detail->attachments),
            array_map(static fn (InboxItem $other): string => $other->id(), $detail->thread),
            array_map(static fn (OutputView $output): InboxOutput => new InboxOutput($output->output->kind(), $output->output->recordId(), $output->output->addedAt()), $outputs),
            $item->emailActivityId(),
            $item->duplicateOfPurpose(),
        );
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
