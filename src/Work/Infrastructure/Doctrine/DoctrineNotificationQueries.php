<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Identity\Domain\Membership;
use App\Tenancy\Application\TenantClock;
use App\Work\Application\NotificationFilter;
use App\Work\Application\NotificationPage;
use App\Work\Application\NotificationQueries;
use App\Work\Application\NotificationRow;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\Notification;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * The inbox in DQL, kept to the tasks the recipient sees now through
 * ScopedDql on their own scope, and to tasks out of the trash.
 */
final readonly class DoctrineNotificationQueries implements NotificationQueries
{
    public function __construct(private EntityManagerInterface $entityManager, private WorkVisibility $visibility, private TenantClock $clock)
    {
    }

    public function unreadCount(Membership $recipient): int
    {
        $query = $this->query($recipient, 'SELECT COUNT(n.id)', ' AND n.readAt IS NULL AND n.doneAt IS NULL');

        return (int) $query->getSingleScalarResult();
    }

    public function inbox(Membership $recipient, NotificationFilter $filter = NotificationFilter::All, bool $done = false, int $page = 1): NotificationPage
    {
        $page = max(1, $page);
        $where = ' AND n.doneAt IS '.($done ? 'NOT ' : '').'NULL'.match ($filter) {
            NotificationFilter::All => '',
            NotificationFilter::Mentions => ' AND n.mentioned = :yes',
            NotificationFilter::Assigned => ' AND n.assigned = :yes',
        };
        $query = $this->query($recipient, 'SELECT n, t, p, a, au', $where.' ORDER BY n.createdAt DESC, n.id DESC', ' JOIN n.actor a JOIN a.user au');
        if (NotificationFilter::All !== $filter) {
            $query->setParameter('yes', true, Types::BOOLEAN);
        }
        /** @var list<Notification> $notifications */
        $notifications = $query->setFirstResult(($page - 1) * self::PAGE_SIZE)->setMaxResults(self::PAGE_SIZE + 1)->getResult();
        $zone = $this->clock->timezone();
        $rows = array_map(static fn (Notification $notification): NotificationRow => new NotificationRow(
            $notification->id(),
            $notification->task()->id(),
            $notification->task()->title(),
            $notification->task()->project()->name(),
            $notification->kind(),
            $notification->actor()->user()->displayName(),
            $notification->createdAt()->setTimezone($zone),
            $notification->isRead(),
            $notification->events(),
            $notification->isDone(),
        ), \array_slice($notifications, 0, self::PAGE_SIZE));

        return new NotificationPage($rows, $page, \count($notifications) > self::PAGE_SIZE);
    }

    /**
     * @return Query<mixed, mixed>
     */
    private function query(Membership $recipient, string $select, string $where, string $joins = ''): Query
    {
        $seen = ScopedDql::tasks($this->visibility->scopeOf($recipient), 't');

        return $seen->apply($this->entityManager->createQuery(
            $select.' FROM '.Notification::class.' n JOIN n.task t JOIN t.project p'.$joins.$seen->joins
            .' WHERE n.recipient = :recipient AND t.deletedAt IS NULL'.$seen->where.$where,
        ))->setParameter('recipient', $recipient->id());
    }
}
