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
use App\Work\Application\TaskTime;
use App\Work\Application\TimeDay;
use App\Work\Application\TimeEntries;
use App\Work\Application\TimeEntryRow;
use App\Work\Application\TimeQueries;
use App\Work\Application\TimeWeek;
use App\Work\Application\WorkScope;
use App\Work\Application\WorkVisibility;
use App\Work\Domain\Task;
use App\Work\Domain\TimeEntry;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The read side of time in DQL, under the tenant filter. A query reads the
 * entries of one task or of one person's week, a few dozen at most, and keeps
 * to the scope of WorkVisibility in PHP through WorkScope, the rule commands
 * use too.
 */
final readonly class DoctrineTimeQueries implements TimeQueries
{
    private const string ENTRIES = 'SELECT e, p, t, m, u FROM '.TimeEntry::class.' e JOIN e.project p LEFT JOIN e.task t JOIN e.membership m JOIN m.user u';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private WorkVisibility $visibility,
        private TimeEntries $entries,
        private People $people,
        private AuditActor $actor,
        private OwnerCheck $owner,
    ) {
    }

    public function ofTask(string $taskId): ?TaskTime
    {
        $task = $this->entityManager->getRepository(Task::class)->findOneBy(['id' => $taskId, 'deletedAt' => null]);
        if (!$task instanceof Task || !$this->visibility->canSeeTask($task)) {
            return null;
        }
        /** @var list<TimeEntry> $entries */
        $entries = $this->entityManager
            ->createQuery(self::ENTRIES.' WHERE e.task = :task ORDER BY e.workedOn DESC, e.createdAt DESC, e.id DESC')
            ->setParameter('task', $task->id())
            ->getResult();
        $rows = $this->rows($entries, $this->visibility->scope(), $this->me());

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
        /** @var list<TimeEntry> $entries */
        $entries = $this->entityManager
            ->createQuery(self::ENTRIES.' WHERE e.membership = :membership AND e.workedOn >= :from AND e.workedOn < :until ORDER BY e.workedOn ASC, e.createdAt ASC, e.id ASC')
            ->setParameter('membership', $person->id())
            ->setParameter('from', $monday, Types::DATE_IMMUTABLE)
            ->setParameter('until', $monday->modify('+7 days'), Types::DATE_IMMUTABLE)
            ->getResult();
        $byDay = [];
        foreach ($this->rows($entries, $this->visibility->scope(), $me) as $row) {
            $byDay[$row->workedOn->format('Y-m-d')][] = $row;
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
     * @param ?string $me    the membership of the signed-in person
     * @param bool    $owner whether they are an owner of the organisation
     */
    private function row(TimeEntry $entry, ?string $me, bool $owner): TimeEntryRow
    {
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
        );
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
