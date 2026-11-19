<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Foresi\Identity\Domain\Membership;
use Foresi\Instance\Application\InstanceClock;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Shared\Infrastructure\View\DqlConditions;
use Foresi\Work\Application\NotificationPage;
use Foresi\Work\Application\NotificationQueries;
use Foresi\Work\Application\NotificationRow;
use Foresi\Work\Application\View\NotificationLists;
use Foresi\Work\Application\WorkVisibility;
use Foresi\Work\Domain\Notification;

/**
 * The inbox in DQL, kept to the tasks the recipient sees now through
 * ScopedDql on their own scope, and to tasks out of the trash.
 *
 * @phpstan-type Parameters array<string, array{mixed, string|ArrayParameterType|null}>
 */
final readonly class DoctrineNotificationQueries implements NotificationQueries
{
    public function __construct(private EntityManagerInterface $entityManager, private WorkVisibility $visibility, private InstanceClock $clock)
    {
    }

    public function unreadCount(Membership $recipient): int
    {
        $query = $this->query($recipient, 'SELECT COUNT(n.id)', ' AND n.readAt IS NULL AND n.doneAt IS NULL');

        return (int) $query->getSingleScalarResult();
    }

    public function inbox(Membership $recipient, ViewFilter $view = new ViewFilter(), int $page = 1): NotificationPage
    {
        $page = max(1, $page);
        [$condition, $parameters] = $this->inView($view);
        $query = $this->query($recipient, 'SELECT n, t, p, a, au', ' AND '.$condition.' ORDER BY n.createdAt DESC, n.id DESC', ' JOIN n.actor a JOIN a.user au');
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
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
     * The rows a view holds. Why a row came, whether it is read or done are the
     * flags and times of the row; when is the time of its latest event. A view that
     * says nothing of done holds the rows not done, as the inbox always did.
     *
     * @return array{string, Parameters}
     */
    private function inView(ViewFilter $view): array
    {
        $derived = static fn (Condition $condition): array => [match ($condition->field) {
            NotificationLists::REASON => DqlConditions::derivedEnum($condition, [
                NotificationLists::MENTIONS => 'n.mentioned = TRUE',
                NotificationLists::ASSIGNED => 'n.assigned = TRUE',
            ]),
            NotificationLists::UNREAD => DqlConditions::derivedBoolean($condition, 'n.readAt IS NULL'),
            NotificationLists::DONE => DqlConditions::derivedBoolean($condition, 'n.doneAt IS NOT NULL'),
            default => throw new \LogicException(\sprintf('The field "%s" of the inbox has no SQL.', $condition->field)),
        }, []];
        [$condition, $parameters] = new DqlConditions(NotificationLists::list(), [NotificationLists::AT => 'n.createdAt'], $derived(...))
            ->compile($view, $this->clock->today(...), 'v');

        return [[] === $view->on(NotificationLists::DONE) ? 'n.doneAt IS NULL AND '.$condition : $condition, $parameters];
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
