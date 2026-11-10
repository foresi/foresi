<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Infrastructure\Doctrine;

use App\Audit\Application\AuditActor;
use App\Identity\Application\OwnerCheck;
use App\Identity\Application\People;
use App\Identity\Domain\Membership;
use App\Work\Application\InvoiceNumbers;
use App\Work\Application\TaskTime;
use App\Work\Application\TimeDay;
use App\Work\Application\TimeEntries;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeGrouping;
use App\Work\Application\TimeQueries;
use App\Work\Application\TimeReport;
use App\Work\Application\TimeReportFilter;
use App\Work\Application\TimeTarget;
use App\Work\Application\TimeTotal;
use App\Work\Application\TimeWeek;
use App\Work\Application\WorkScope;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\Project;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;

/**
 * The read side of time in DQL, under the tenant filter. A query keeps to the
 * scope of WorkVisibility through ScopedDql, so a report over a year reads only
 * what the person sees and adds it up in the database; a single entry is
 * checked in PHP through WorkScope, the rule commands use too. Time on a task
 * in the trash is left out everywhere. The lines of a task and of a week name
 * the invoice that billed them.
 */
final readonly class DoctrineTimeQueries implements TimeQueries
{
    private const string ENTRIES = 'SELECT e, p, c, t, m, u FROM '.TimeEntry::class.' e JOIN e.project p LEFT JOIN p.client c LEFT JOIN e.task t JOIN e.membership m JOIN m.user u';
    private const string FROM = ' FROM '.TimeEntry::class.' e JOIN e.project p LEFT JOIN p.client c LEFT JOIN e.task t JOIN e.membership m JOIN m.user u';
    private const string NOT_TRASHED = ' AND (t.id IS NULL OR t.deletedAt IS NULL)';
    public const int PAGE_SIZE = 100;
    private const int BATCH = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkVisibility $visibility,
        private TimeEntries $entries,
        private People $people,
        private AuditActor $actor,
        private OwnerCheck $owner,
        private InvoiceNumbers $invoices,
    ) {
    }

    public function ofTask(string $taskId): ?TaskTime
    {
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['id' => $taskId, 'deletedAt' => null]);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
            return null;
        }
        // The task is seen, and its time with it.
        /** @var list<TimeEntry> $entries */
        $entries = $this->entityManager
            ->createQuery(self::ENTRIES.' WHERE e.task = :task ORDER BY e.workedOn DESC, e.createdAt DESC, e.id DESC')
            ->setParameter('task', $task->id())
            ->getResult();
        $me = $this->me();
        $owner = $this->owner->isOwner();
        $numbers = $this->invoiceNumbers($entries);
        $rows = array_map(fn (TimeEntry $entry): TimeEntryRow => $this->row($entry, $me?->id(), $owner, $numbers), $entries);

        return new TaskTime($task->id(), $rows, $this->total($rows), $task->estimateMinutes());
    }

    public function week(\DateTimeImmutable $day, ?string $membershipId = null): ?TimeWeek
    {
        $me = $this->me();
        $person = null === $membershipId || $membershipId === $me?->id() ? $me : $this->people->membership($membershipId);
        if (!$person instanceof Membership || ($person->id() !== $me?->id() && !$this->owner->isOwner())) {
            return null;
        }
        $monday = $day->setISODate((int) $day->format('o'), (int) $day->format('W'))->setTime(0, 0);
        $seen = ScopedDql::time($this->visibility->scope(), 'p', 't');
        /** @var list<TimeEntry> $entries */
        $entries = $seen->apply($this->entityManager
            ->createQuery(self::ENTRIES.$seen->joins.' WHERE e.membership = :membership AND e.workedOn >= :from AND e.workedOn < :until'.self::NOT_TRASHED.$seen->where.' ORDER BY e.workedOn ASC, e.createdAt ASC, e.id ASC'))
            ->setParameter('membership', $person->id())
            ->setParameter('from', $monday, Types::DATE_IMMUTABLE)
            ->setParameter('until', $monday->modify('+7 days'), Types::DATE_IMMUTABLE)
            ->getResult();
        $owner = $this->owner->isOwner();
        $numbers = $this->invoiceNumbers($entries);
        $byDay = [];
        foreach ($entries as $entry) {
            $byDay[$entry->workedOn()->format('Y-m-d')][] = $this->row($entry, $me?->id(), $owner, $numbers);
        }
        $days = [];
        for ($i = 0; $i < 7; ++$i) {
            $date = $monday->modify(sprintf('+%d days', $i));
            $rows = $byDay[$date->format('Y-m-d')] ?? [];
            $days[] = new TimeDay($date, $rows, $this->total($rows));
        }

        return new TimeWeek($person->id(), $monday, $days, array_sum(array_map(static fn (TimeDay $day): int => $day->totalMinutes, $days)));
    }

    public function running(): ?TimeEntryRow
    {
        $me = $this->me();
        if (!$me instanceof Membership) {
            return null;
        }
        $entry = $this->entries->running($me);

        return $entry instanceof TimeEntry ? $this->row($entry, $me->id(), $this->owner->isOwner()) : null;
    }

    public function taskTarget(string $taskId): ?TimeTarget
    {
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['id' => $taskId, 'deletedAt' => null]);
        if (!$task instanceof Task || !$this->me() instanceof Membership) {
            return null;
        }
        $project = $task->project();

        return $project->isArchived() || !$this->visibility->canLogTime($project, $task)
            ? null
            : new TimeTarget($project->id(), $project->name(), $task->id(), $task->title(), $project->billsTimeByDefault());
    }

    public function projectTarget(string $projectId): ?TimeTarget
    {
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['id' => $projectId]);
        if (!$project instanceof Project || !$this->me() instanceof Membership) {
            return null;
        }

        return $project->isArchived() || !$this->visibility->canLogTime($project, null)
            ? null
            : new TimeTarget($project->id(), $project->name(), null, null, $project->billsTimeByDefault());
    }

    public function taskTargets(string $projectId): array
    {
        $project = $this->entityManager->getRepository(Project::class)->findOneBy(['id' => $projectId]);
        if (!$project instanceof Project || $project->isArchived() || !$this->me() instanceof Membership || !$this->visibility->canManage()) {
            return [];
        }
        $seen = ScopedDql::tasks($this->visibility->scope(), 't');
        /** @var list<Task> $tasks */
        $tasks = $seen->apply($this->entityManager
            ->createQuery('SELECT t FROM '.Task::class.' t'.$seen->joins.' WHERE t.project = :project AND t.deletedAt IS NULL'.$seen->where.' ORDER BY t.title ASC, t.id ASC'))
            ->setParameter('project', $project->id())
            ->getResult();

        return array_map(static fn (Task $task): TimeTarget => new TimeTarget($project->id(), $project->name(), $task->id(), $task->title(), $project->billsTimeByDefault()), $tasks);
    }

    public function report(TimeReportFilter $filter, TimeGrouping $grouping, int $page = 1): TimeReport
    {
        [$where, $parameters, $seen] = $this->reportWhere($filter);
        $billable = 'SUM(CASE WHEN e.billable = :yes THEN e.minutes ELSE 0 END)';
        [$select, $group] = match ($grouping) {
            TimeGrouping::Project => ['p.id AS id, p.name AS label', 'p.id, p.name'],
            TimeGrouping::Person => ['m.id AS id, u.displayName AS label', 'm.id, u.displayName'],
            TimeGrouping::Task => ['p.id AS id, t.title AS label, p.name AS detail, t.id AS task', 'p.id, p.name, t.id, t.title'],
        };
        /** @var list<array{id: string, label: ?string, detail?: string, task?: ?string, minutes: int|string|null, billable: int|string|null, entries: int|string}> $lines */
        $lines = $this->bind($seen->apply($this->entityManager
            ->createQuery('SELECT '.$select.', SUM(e.minutes) AS minutes, '.$billable.' AS billable, COUNT(e.id) AS entries'.self::FROM.$seen->joins.' WHERE '.$where.' GROUP BY '.$group)), $parameters)
            ->setParameter('yes', true, Types::BOOLEAN)
            ->getArrayResult();
        $totals = array_map(static fn (array $line): TimeTotal => new TimeTotal(
            $line['id'].(TimeGrouping::Task === $grouping ? '/'.($line['task'] ?? '') : ''),
            TimeGrouping::Task === $grouping && null === ($line['task'] ?? null) ? null : (string) $line['label'],
            $line['detail'] ?? null,
            (int) $line['minutes'],
            (int) $line['billable'],
            (int) $line['entries'],
        ), $lines);
        usort($totals, static fn (TimeTotal $a, TimeTotal $b): int => [$b->minutes, $a->detail ?? '', $a->label ?? ''] <=> [$a->minutes, $b->detail ?? '', $b->label ?? '']);
        $count = array_sum(array_map(static fn (TimeTotal $total): int => $total->entries, $totals));
        $pages = max(1, (int) ceil($count / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $me = $this->me();
        $owner = $this->owner->isOwner();
        /** @var list<TimeEntry> $entries */
        $entries = $this->bind($seen->apply($this->entityManager
            ->createQuery(self::ENTRIES.$seen->joins.' WHERE '.$where.' ORDER BY e.workedOn DESC, e.createdAt DESC, e.id DESC')), $parameters)
            ->setFirstResult(($page - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getResult();

        return new TimeReport(
            $totals,
            array_sum(array_map(static fn (TimeTotal $total): int => $total->minutes, $totals)),
            array_sum(array_map(static fn (TimeTotal $total): int => $total->billableMinutes, $totals)),
            $count,
            array_map(fn (TimeEntry $entry): TimeEntryRow => $this->row($entry, $me?->id(), $owner), $entries),
            $page,
            $pages,
        );
    }

    public function reportEntries(TimeReportFilter $filter): iterable
    {
        [$where, $parameters, $seen] = $this->reportWhere($filter);
        $me = $this->me()?->id();
        $owner = $this->owner->isOwner();
        // Keyset batches: a page of a long report never shifts under the one before it.
        $after = null;
        do {
            $query = $this->bind($seen->apply($this->entityManager
                ->createQuery(self::ENTRIES.$seen->joins.' WHERE '.$where.(null === $after ? '' : ' AND (e.workedOn > :after_day OR (e.workedOn = :after_day AND e.id > :after_id))').' ORDER BY e.workedOn ASC, e.id ASC')), $parameters)
                ->setMaxResults(self::BATCH);
            if (null !== $after) {
                $query->setParameter('after_day', $after->workedOn(), Types::DATE_IMMUTABLE)->setParameter('after_id', $after->id());
            }
            /** @var list<TimeEntry> $entries */
            $entries = $query->getResult();
            foreach ($entries as $entry) {
                yield $this->row($entry, $me, $owner);
            }
            $after = [] === $entries ? null : $entries[array_key_last($entries)];
        } while (self::BATCH === \count($entries));
    }

    /**
     * The condition of a report, its values and the scope of the person.
     *
     * @return array{string, array<string, array{mixed, string}>, ScopedDql}
     */
    private function reportWhere(TimeReportFilter $filter): array
    {
        $where = 'e.minutes IS NOT NULL AND e.workedOn >= :from AND e.workedOn <= :until'.self::NOT_TRASHED;
        $parameters = [
            'from' => [$filter->from, Types::DATE_IMMUTABLE],
            'until' => [$filter->until, Types::DATE_IMMUTABLE],
        ];
        if (null !== $filter->projectId) {
            $where .= ' AND p.id = :project';
            $parameters['project'] = [$filter->projectId, Types::GUID];
        }
        if (null !== $filter->clientId) {
            $where .= ' AND c.id = :client';
            $parameters['client'] = [$filter->clientId, Types::GUID];
        }
        if (null !== $filter->membershipId) {
            $where .= ' AND m.id = :membership';
            $parameters['membership'] = [$filter->membershipId, Types::GUID];
        }
        if (null !== $filter->billable) {
            $where .= ' AND e.billable = :billable';
            $parameters['billable'] = [$filter->billable, Types::BOOLEAN];
        }
        if (null !== $filter->invoiced) {
            $where .= ' AND e.invoiceLineId IS '.($filter->invoiced ? 'NOT ' : '').'NULL';
        }
        // Guests never see time; nor does anyone without a membership.
        $seen = ScopedDql::time($this->visibility->canManage() ? $this->visibility->scope() : WorkScope::nothing(), 'p', 't');

        return [$where.$seen->where, $parameters, $seen];
    }

    /**
     * @template TKey
     * @template TResult
     *
     * @param Query<TKey, TResult>                $query
     * @param array<string, array{mixed, string}> $parameters
     *
     * @return Query<TKey, TResult>
     */
    private function bind(Query $query, array $parameters): Query
    {
        foreach ($parameters as $name => [$value, $type]) {
            $query->setParameter($name, $value, $type);
        }

        return $query;
    }

    public function editable(string $entryId): ?TimeEntryRow
    {
        $me = $this->me();
        $entry = $this->entries->entry($entryId);
        if (!$me instanceof Membership || !$entry instanceof TimeEntry) {
            return null;
        }
        $rows = $this->rows([$entry], $this->visibility->scope(), $me);

        return [] !== $rows && $rows[0]->editable ? $rows[0] : null;
    }

    /**
     * The entries seen, those on a task in the trash left out.
     *
     * @param list<TimeEntry> $entries
     *
     * @return list<TimeEntryRow>
     */
    private function rows(array $entries, WorkScope $scope, ?Membership $me): array
    {
        $owner = $this->owner->isOwner();
        $rows = [];
        foreach ($entries as $entry) {
            $task = $entry->task();
            if (true !== $task?->isDeleted() && $scope->seesTime($entry->project(), $task)) {
                $rows[] = $this->row($entry, $me?->id(), $owner);
            }
        }

        return $rows;
    }

    /**
     * @param ?string               $me      the membership of the signed-in person
     * @param bool                  $owner   whether they are an owner of the organisation
     * @param array<string, string> $numbers invoice numbers by invoice id, for the lines that name them
     */
    private function row(TimeEntry $entry, ?string $me, bool $owner, array $numbers = []): TimeEntryRow
    {
        $invoiceId = $entry->invoiceId();

        $mine = $entry->membership()->id() === $me;

        return new TimeEntryRow(
            $entry->id(),
            $entry->project()->id(),
            $entry->project()->name(),
            $entry->task()?->id(),
            $entry->task()?->title(),
            $entry->membership()->id(),
            $entry->membership()->user()->displayName(),
            $entry->workedOn(),
            $entry->minutes(),
            $entry->startedAt(),
            $entry->endedAt(),
            $entry->note(),
            $entry->isBillable(),
            $entry->isRunning(),
            $entry->isInvoiced(),
            !$entry->isInvoiced() && ($mine || $owner),
            $entry->project()->client()?->displayName(),
            $invoiceId,
            null === $invoiceId ? null : $numbers[$invoiceId] ?? null,
        );
    }

    /**
     * The numbers of the invoices that billed the entries.
     *
     * @param list<TimeEntry> $entries
     *
     * @return array<string, string> by invoice id
     */
    private function invoiceNumbers(array $entries): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (TimeEntry $entry): ?string => $entry->invoiceId(), $entries), static fn (?string $id): bool => null !== $id)));

        return [] === $ids ? [] : $this->invoices->numbersOf($ids);
    }

    /**
     * @param list<TimeEntryRow> $rows
     */
    private function total(array $rows): int
    {
        return array_sum(array_map(static fn (TimeEntryRow $row): int => $row->minutes ?? 0, $rows));
    }

    /**
     * The active membership of the signed-in person.
     */
    private function me(): ?Membership
    {
        $userId = $this->actor->userId();
        $membership = null === $userId ? null : $this->people->membershipOf($userId);

        return $membership instanceof Membership && $membership->isActive() ? $membership : null;
    }
}
