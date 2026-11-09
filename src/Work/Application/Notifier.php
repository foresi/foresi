<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Application\AuditActor;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\Task;
use App\Work\Domain\TaskComment;
use Psr\Clock\ClockInterface;

/**
 * Puts what happened on a task into the inboxes of the people it concerns:
 * a mention, becoming responsible, a comment or a status change of a task
 * they watch, a task shared with them. The commands call it right after the
 * change, inside their unit of work, so the inbox is saved with the change or
 * not at all; mail is sent later from the rows.
 *
 * Nobody hears about their own action (Asana, GitHub). Only active people who
 * see the task hear about it, and only the people of the organisation about
 * an internal comment, which guests never read. Each event goes to a person's
 * open row of the task, or opens one; one action tells each person once.
 *
 * Sharing tells only the person a task is shared with directly; a shared
 * project or section tells nobody, as it would mean a row for every task in it.
 */
final readonly class Notifier
{
    public function __construct(
        private Notifications $notifications,
        private Tasks $tasks,
        private WorkVisibility $visibility,
        private People $people,
        private AuditActor $auditActor,
        private ClockInterface $clock,
    ) {
    }

    /**
     * A new comment: the people it mentions hear of the mention, the other
     * watchers of the comment.
     *
     * @param list<Membership> $mentioned
     */
    public function commented(TaskComment $comment, array $mentioned): void
    {
        $this->mentioned($comment, $mentioned);
        $skip = [];
        foreach ($mentioned as $membership) {
            $skip[$membership->id()] = true;
        }
        foreach ($this->tasks->watchers($comment->task()) as $watcher) {
            $membership = $watcher->membership();
            if (!isset($skip[$membership->id()])) {
                $this->tell($membership, $comment->task(), NotificationKind::Commented, $comment->isInternal());
            }
        }
    }

    /**
     * People a comment mentions, when it is written or when an edit adds them.
     *
     * @param list<Membership> $mentioned
     */
    public function mentioned(TaskComment $comment, array $mentioned): void
    {
        foreach ($mentioned as $membership) {
            $this->tell($membership, $comment->task(), NotificationKind::Mentioned, $comment->isInternal());
        }
    }

    public function assigned(Task $task, Membership $responsible): void
    {
        $this->tell($responsible, $task, NotificationKind::Assigned);
    }

    public function statusChanged(Task $task): void
    {
        foreach ($this->tasks->watchers($task) as $watcher) {
            $this->tell($watcher->membership(), $task, NotificationKind::StatusChanged);
        }
    }

    /**
     * The share is not saved yet when this is called; a task shared with a
     * person, who is active as only they are shared with, is one they see.
     */
    public function shared(Task $task, Membership $membership): void
    {
        $this->tell($membership, $task, NotificationKind::Shared, false, false);
    }

    private function tell(Membership $recipient, Task $task, NotificationKind $kind, bool $internal = false, bool $checkSight = true): void
    {
        $actor = $this->actor();
        if (!$actor instanceof Membership || $actor->id() === $recipient->id()) {
            return;
        }
        if ($internal && !$recipient->role()->isStaff()) {
            return;
        }
        if ($checkSight && !$this->visibility->canMembershipSeeTask($recipient, $task)) {
            return;
        }
        $now = $this->clock->now();
        $open = $this->notifications->openFor($recipient, $task);
        if ($open instanceof Notification) {
            $open->record($kind, $actor, $now);
        } else {
            $this->notifications->add(new Notification($recipient, $task, $kind, $actor, $now));
        }
    }

    /**
     * The membership of the signed-in person, who did what happened.
     */
    private function actor(): ?Membership
    {
        $userId = $this->auditActor->userId();

        return null === $userId ? null : $this->people->membershipOf($userId);
    }
}
