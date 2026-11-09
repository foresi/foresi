<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\Notification;
use App\Work\Domain\Task;

/**
 * The notifications of the people of the active tenant.
 */
interface Notifications
{
    /**
     * The row of the person for the task that is not done, if there is one.
     */
    public function openFor(Membership $recipient, Task $task): ?Notification;

    /**
     * A row of the person, done or not; null for a row of someone else.
     */
    public function ofRecipient(string $id, Membership $recipient): ?Notification;

    /**
     * A row of anyone in the tenant, for the worker that mails it.
     */
    public function find(string $id): ?Notification;

    /**
     * The rows of everyone not read, not done and not mailed yet, newest first.
     *
     * @return list<Notification>
     */
    public function waitingForMail(): array;

    /**
     * The rows of the person not read and not done.
     *
     * @return list<Notification>
     */
    public function unread(Membership $recipient): array;

    /**
     * Every row of the task, whoever it is for.
     *
     * @return list<Notification>
     */
    public function ofTask(Task $task): array;

    /**
     * The rows marked done before the moment.
     *
     * @return list<Notification>
     */
    public function doneBefore(\DateTimeImmutable $moment): array;

    public function add(Notification $notification): void;

    public function remove(Notification $notification): void;
}
