<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Events;

use Foresi\Audit\Application\AuditActor;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Contract\Event\InboxItemReceived;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Shared\Application\Event\EventSubjectAccess;
use Foresi\Shared\Contract\Event\EventSubject;

/**
 * An inbox item is seen by its owner alone, so one person's mail never reaches
 * another's feed or webhook. It is seen while it exists, in the trash too; an
 * item that is gone is seen by no one.
 */
final readonly class InboxEventSubjectAccess implements EventSubjectAccess
{
    public function __construct(private ItemStore $items, private AuditActor $actor)
    {
    }

    public function module(): string
    {
        return 'inbox';
    }

    public function sees(EventSubject $subject): bool
    {
        return $this->seenBy($this->actor->userId(), $subject);
    }

    public function userSees(string $userId, EventSubject $subject): bool
    {
        return $this->seenBy($userId, $subject);
    }

    private function seenBy(?string $userId, EventSubject $subject): bool
    {
        if (InboxItemReceived::SUBJECT !== $subject->type) {
            return false;
        }
        $item = $this->items->item($subject->id);

        return $item instanceof InboxItem && $item->isOwnedBy($userId);
    }
}
