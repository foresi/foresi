<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Meetings\Application\MeetingsStore;
use Foresi\Meetings\Domain\Attendee;
use Foresi\Meetings\Domain\Meeting;
use Foresi\Meetings\Domain\MeetingLink;
use Foresi\Meetings\Domain\MeetingSeries;
use Foresi\Meetings\Domain\SeriesMember;

/**
 * The series and meetings in Doctrine.
 */
final readonly class DoctrineMeetingsStore implements MeetingsStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function series(string $id): ?MeetingSeries
    {
        return $this->em->find(MeetingSeries::class, $id);
    }

    public function seriesNamed(string $name): ?MeetingSeries
    {
        /** @var MeetingSeries|null */
        return $this->em->createQuery('SELECT s FROM '.MeetingSeries::class.' s WHERE LOWER(s.name) = :name')
            ->setParameter('name', mb_strtolower($name))
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    public function allSeries(): array
    {
        /** @var list<MeetingSeries> $series */
        $series = $this->em->createQuery('SELECT s FROM '.MeetingSeries::class.' s ORDER BY s.name ASC, s.id ASC')->getResult();
        usort($series, static fn (MeetingSeries $a, MeetingSeries $b): int => strnatcasecmp($a->name(), $b->name()));

        return $series;
    }

    public function members(MeetingSeries $series): array
    {
        /** @var list<SeriesMember> */
        return $this->em->getRepository(SeriesMember::class)->findBy(['series' => $series]);
    }

    public function membersBySeries(): array
    {
        $result = [];
        /** @var list<array{series: string, membershipId: string}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(m.series) AS series, m.membershipId FROM '.SeriesMember::class.' m')->getArrayResult();
        foreach ($rows as $row) {
            $result[$row['series']][] = $row['membershipId'];
        }

        return $result;
    }

    public function seriesStats(): array
    {
        $result = [];
        /** @var list<array{series: string, meetings: int|string, last: string|\DateTimeInterface|null}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(m.series) AS series, COUNT(m.id) AS meetings, MAX(m.heldOn) AS last FROM '.Meeting::class.' m WHERE m.series IS NOT NULL AND m.deletedAt IS NULL GROUP BY m.series')->getArrayResult();
        foreach ($rows as $row) {
            $last = $row['last'];
            $result[$row['series']] = [
                'count' => (int) $row['meetings'],
                'last' => $last instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($last) : (\is_string($last) ? new \DateTimeImmutable(substr($last, 0, 10)) : null),
            ];
        }

        return $result;
    }

    public function meeting(string $id): ?Meeting
    {
        return $this->em->find(Meeting::class, $id);
    }

    public function attendees(Meeting $meeting): array
    {
        /** @var list<Attendee> */
        return $this->em->getRepository(Attendee::class)->findBy(['meeting' => $meeting]);
    }

    public function attendeesOf(array $meetingIds): array
    {
        if ([] === $meetingIds) {
            return [];
        }
        $result = [];
        /** @var list<array{meeting: string, membershipId: string}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(a.meeting) AS meeting, a.membershipId FROM '.Attendee::class.' a WHERE a.meeting IN (:ids)')
            ->setParameter('ids', $meetingIds, ArrayParameterType::STRING)
            ->getArrayResult();
        foreach ($rows as $row) {
            $result[$row['meeting']][] = $row['membershipId'];
        }

        return $result;
    }

    public function links(Meeting $meeting): array
    {
        /** @var list<MeetingLink> */
        return $this->em->getRepository(MeetingLink::class)->findBy(['meeting' => $meeting]);
    }

    public function linksOf(array $meetingIds): array
    {
        if ([] === $meetingIds) {
            return [];
        }
        $result = [];
        /** @var list<array{meeting: string, kind: string, targetId: string}> $rows */
        $rows = $this->em->createQuery('SELECT IDENTITY(l.meeting) AS meeting, l.kind, l.targetId FROM '.MeetingLink::class.' l WHERE l.meeting IN (:ids) ORDER BY l.kind ASC')
            ->setParameter('ids', $meetingIds, ArrayParameterType::STRING)
            ->getArrayResult();
        foreach ($rows as $row) {
            $result[$row['meeting']][] = [$row['kind'], $row['targetId']];
        }

        return $result;
    }

    public function trashedBefore(\DateTimeImmutable $moment): array
    {
        /** @var list<Meeting> */
        return $this->em->createQuery('SELECT m FROM '.Meeting::class.' m WHERE m.deletedAt IS NOT NULL AND m.deletedAt < :moment ORDER BY m.deletedAt ASC')
            ->setParameter('moment', $moment, 'datetime_immutable')
            ->getResult();
    }

    public function add(object $entity): void
    {
        $this->em->persist($entity);
    }

    public function remove(object $entity): void
    {
        $this->em->remove($entity);
    }
}
