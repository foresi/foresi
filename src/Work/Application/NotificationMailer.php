<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Domain\Membership;
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\TaskComment;

/**
 * Writes and sends the e-mails of notifications through the installation's
 * system mailer, in the recipient's language, with a link to the task, to the
 * settings and one to stop that kind of e-mail.
 */
interface NotificationMailer
{
    /**
     * Whether the installation can send e-mail at all; without it the
     * notifications stay in the inbox.
     */
    public function isConfigured(): bool;

    /**
     * The e-mail of one mention or assignment.
     *
     * @param TaskComment|null $comment the comment of a mention, for its excerpt
     */
    public function immediate(Notification $notification, NotificationKind $kind, Membership $actor, ?TaskComment $comment): void;

    /**
     * The daily digest of the rows, newest first.
     *
     * @param non-empty-list<Notification> $notifications
     */
    public function digest(Membership $recipient, array $notifications): void;
}
