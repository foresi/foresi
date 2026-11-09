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
use App\Work\Domain\Task;
use Psr\Clock\ClockInterface;

/**
 * The signed-in person going through their own inbox: opening a task reads
 * its row, all can be read at once, a row is done or back again (GitHub
 * Done, ClickUp Cleared). Nobody touches the inbox of someone else.
 */
final readonly class ManageNotifications
{
    public function __construct(
        private Notifications $notifications,
        private Tasks $tasks,
        private People $people,
        private AuditActor $actor,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * Opening the task reads its row; nothing happens when there is none.
     */
    public function markRead(string $taskId): void
    {
        $me = $this->me();
        $task = $this->tasks->task($taskId);
        $open = $me instanceof Membership && $task instanceof Task ? $this->notifications->openFor($me, $task) : null;
        if ($open instanceof Notification) {
            $this->unit->run(fn () => $open->markRead($this->clock->now()));
        }
    }

    public function markAllRead(): void
    {
        $me = $this->me();
        if (!$me instanceof Membership) {
            return;
        }
        $this->unit->run(function () use ($me): void {
            $now = $this->clock->now();
            foreach ($this->notifications->unread($me) as $notification) {
                $notification->markRead($now);
            }
        });
    }

    /**
     * @throws WorkRefused when the row is not the person's
     */
    public function markDone(string $notificationId): void
    {
        $notification = $this->mine($notificationId);
        $this->unit->run(fn () => $notification->markDone($this->clock->now()));
    }

    /**
     * Back from done to the inbox. When a newer event has opened a new row of
     * the task meanwhile, that row shows the task and this one stays done:
     * one open row per person and task.
     *
     * @throws WorkRefused when the row is not the person's
     */
    public function markUndone(string $notificationId): void
    {
        $notification = $this->mine($notificationId);
        // A row that is not done is the open one itself.
        if ($this->notifications->openFor($notification->recipient(), $notification->task()) instanceof Notification) {
            return;
        }
        $this->unit->run(static fn () => $notification->markUndone());
    }

    /**
     * @throws WorkRefused
     */
    private function mine(string $id): Notification
    {
        $me = $this->me() ?? throw new WorkRefused('not_allowed');

        return $this->notifications->ofRecipient($id, $me) ?? throw new WorkRefused('unknown');
    }

    private function me(): ?Membership
    {
        $userId = $this->actor->userId();

        return null === $userId ? null : $this->people->membershipOf($userId);
    }
}
