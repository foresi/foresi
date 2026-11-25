<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Infrastructure\Events;

use Foresi\Audit\Application\AuditActor;
use Foresi\Mail\Contract\Messages;
use Foresi\Shared\Application\Event\EventSubjectAccess;
use Foresi\Shared\Domain\Event\EventSubject;

/**
 * A message that arrived for a person's own address is seen by that person only,
 * so one person's mail never reaches another's feed or webhook. A message of an
 * address nobody owns, such as the bank feed, is seen by everyone who reads the
 * events of Mail. A message that is gone is seen by no one.
 */
final readonly class MailEventSubjectAccess implements EventSubjectAccess
{
    public function __construct(private Messages $messages, private AuditActor $actor)
    {
    }

    public function module(): string
    {
        return 'mail';
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
        if ('mail_message' !== $subject->type) {
            return false;
        }
        $message = $this->messages->find($subject->id);
        if (!$message instanceof \Foresi\Mail\Contract\MailMessageView) {
            return false;
        }

        return null === $message->ownerUserId || $message->ownerUserId === $userId;
    }
}
