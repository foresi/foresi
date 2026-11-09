<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Tenancy\Application\TenantClock;
use App\Work\Domain\EmailDelivery;
use App\Work\Domain\Notification;
use App\Work\Domain\NotificationKind;
use App\Work\Domain\NotificationPreference;
use App\Work\Domain\TaskComment;
use Psr\Clock\ClockInterface;

/**
 * Decides which notifications go out by e-mail, and marks them mailed.
 *
 * A mention or becoming responsible is mailed a short while after it happened
 * for a person who wants it at once, unless the row was read or done in the
 * inbox meanwhile, or mailed already (Linear delays and skips what was read;
 * Basecamp sends nothing to who is around). Everything else waits for the
 * daily digest at the person's hour in the organisation's zone (Asana): the
 * rows unread and not mailed from before that hour, of the kinds the person
 * wants in the digest, at most one digest a day. A row read before either
 * goes out is not mailed at all.
 *
 * Nothing is mailed to a person no longer active, about a task they no longer
 * see or that is in the trash, or while the installation has no system mailer:
 * the inbox stays the one channel then.
 */
final readonly class NotificationMailing
{
    public function __construct(
        private Notifications $notifications,
        private NotificationPreferences $preferences,
        private Comments $comments,
        private People $people,
        private WorkVisibility $visibility,
        private NotificationMailer $mailer,
        private TenantClock $tenantClock,
        private ClockInterface $clock,
        private WorkUnit $unit,
    ) {
    }

    /**
     * The preference of the person, or the defaults when they have none.
     */
    public function preferenceOf(Membership $membership): NotificationPreference
    {
        return $this->preferences->of($membership) ?? new NotificationPreference($membership);
    }

    /**
     * Whether the person wants the kind mailed at once: asked when the event
     * happens, and again when the mail is due.
     */
    public function wantsAtOnce(Membership $membership, NotificationKind $kind): bool
    {
        return $kind->isDirect() && EmailDelivery::Immediate === $this->preferenceOf($membership)->mails($kind);
    }

    /**
     * @return bool whether the e-mail was sent
     */
    public function sendImmediate(SendNotificationMail $message): bool
    {
        $kind = NotificationKind::tryFrom($message->kind);
        $notification = $this->notifications->find($message->notificationId);
        if (!$kind instanceof NotificationKind || !$notification instanceof Notification || !$this->isNews($notification) || !$notification->hasDirectUnread()) {
            return false;
        }
        $actor = $this->people->membership($message->actorId);
        if (!$actor instanceof Membership || !$this->mailer->isConfigured() || !$this->wantsAtOnce($notification->recipient(), $kind)) {
            return false;
        }
        $comment = null === $message->commentId ? null : $this->comments->comment($message->commentId);
        $this->mailer->immediate($notification, $kind, $actor, $comment instanceof TaskComment && !$comment->isDeleted() ? $comment : null);
        $this->unit->run(fn () => $notification->markEmailed($this->clock->now()));

        return true;
    }

    /**
     * Sends the digests that are due now in the active tenant.
     *
     * @return int how many people got one
     */
    public function sendDigests(\DateTimeImmutable $now): int
    {
        if (!$this->mailer->isConfigured()) {
            return 0;
        }
        $local = $now->setTimezone($this->tenantClock->timezone());
        $preferences = [];
        foreach ($this->preferences->all() as $preference) {
            $preferences[$preference->membership()->id()] = $preference;
        }
        $waiting = [];
        $recipients = [];
        foreach ($this->notifications->waitingForMail() as $notification) {
            $id = $notification->recipient()->id();
            $recipients[$id] = $notification->recipient();
            $waiting[$id][] = $notification;
        }

        $sent = 0;
        foreach ($waiting as $id => $rows) {
            $preference = $preferences[$id] ?? new NotificationPreference($recipients[$id]);
            $due = $this->due($preference, $rows, $local);
            if ([] === $due) {
                continue;
            }
            $this->mailer->digest($recipients[$id], $due);
            $this->unit->run(function () use ($due, $preference, $now, $preferences, $id): void {
                foreach ($due as $notification) {
                    $notification->markEmailed($now);
                }
                $preference->digestSent($now);
                if (!isset($preferences[$id])) {
                    $this->preferences->add($preference);
                }
            });
            ++$sent;
        }

        return $sent;
    }

    /**
     * The rows of the person's digest when it is due now, else none.
     *
     * @param list<Notification> $rows
     *
     * @return list<Notification>
     */
    private function due(NotificationPreference $preference, array $rows, \DateTimeImmutable $local): array
    {
        $moment = $local->setTime($preference->digestHour(), 0);
        $last = $preference->lastDigestAt();
        if ($local < $moment || ($last instanceof \DateTimeImmutable && $last->setTimezone($local->getTimezone())->format('Y-m-d') === $local->format('Y-m-d'))) {
            return [];
        }

        return array_values(array_filter($rows, fn (Notification $notification): bool => $notification->createdAt() < $moment
            && EmailDelivery::Digest === $preference->mails($notification->kind())
            && $this->isNews($notification)));
    }

    /**
     * Unread, not done, not mailed, for an active person who still sees the task.
     */
    private function isNews(Notification $notification): bool
    {
        $task = $notification->task();

        return !$notification->isRead()
            && !$notification->isDone()
            && !$notification->emailedAt() instanceof \DateTimeImmutable
            && $notification->recipient()->isActive()
            && !$task->isDeleted()
            && $this->visibility->canMembershipSeeTask($notification->recipient(), $task);
    }
}
