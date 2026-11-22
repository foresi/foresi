<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Activities\Application\ActivitiesStore;
use Foresi\Activities\Domain\Activity;
use Foresi\Activities\Domain\ActivityLink;
use Foresi\Activities\Domain\ActivityType;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineActivitiesStore implements ActivitiesStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function type(string $id): ?ActivityType
    {
        return Id::isValid($id) ? $this->em->find(ActivityType::class, $id) : null;
    }

    public function typeByKey(string $key): ?ActivityType
    {
        return $this->em->getRepository(ActivityType::class)->findOneBy(['key' => $key]);
    }

    public function types(bool $withArchived): array
    {
        $query = $this->em->createQueryBuilder()->select('t')->from(ActivityType::class, 't')->orderBy('t.createdAt')->addOrderBy('t.id');
        if (!$withArchived) {
            $query->andWhere('t.archivedAt IS NULL');
        }

        /* @var list<ActivityType> */
        return $query->getQuery()->getResult();
    }

    public function activity(string $id): ?Activity
    {
        return Id::isValid($id) ? $this->em->find(Activity::class, $id) : null;
    }

    public function linkedTo(string $kind, string $id): array
    {
        /* @var list<Activity> */
        return $this->em->createQueryBuilder()->select('a')->from(Activity::class, 'a')
            ->where('a.trashedAt IS NULL')
            ->andWhere('a.id IN (SELECT IDENTITY(l.activity) FROM '.ActivityLink::class.' l WHERE l.kind = :kind AND l.targetId = :target)')
            ->setParameter('kind', $kind)->setParameter('target', $id)
            ->getQuery()->getResult();
    }

    public function plannedOf(string $ownerUserId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /* @var list<Activity> */
        return $this->em->createQueryBuilder()->select('a')->from(Activity::class, 'a')
            ->where('a.ownerUserId = :owner')->andWhere('a.trashedAt IS NULL')->andWhere('a.doneAt IS NULL')
            ->andWhere('(a.plannedAt >= :fromTime AND a.plannedAt < :toTime) OR (a.plannedOn >= :fromDay AND a.plannedOn < :toDay)')
            ->setParameter('owner', $ownerUserId)
            ->setParameter('fromTime', $from->setTimezone(new \DateTimeZone('UTC')), 'datetime_immutable')
            ->setParameter('toTime', $to->setTimezone(new \DateTimeZone('UTC')), 'datetime_immutable')
            ->setParameter('fromDay', new \DateTimeImmutable($from->format('Y-m-d')), 'date_immutable')
            ->setParameter('toDay', new \DateTimeImmutable($to->format('Y-m-d')), 'date_immutable')
            ->getQuery()->getResult();
    }

    public function links(Activity $activity): array
    {
        /* @var list<ActivityLink> */
        return $this->em->createQueryBuilder()->select('l')->from(ActivityLink::class, 'l')
            ->where('l.activity = :activity')->setParameter('activity', $activity)
            ->orderBy('l.kind')->addOrderBy('l.targetId')
            ->getQuery()->getResult();
    }

    public function linksOf(array $activities): array
    {
        if ([] === $activities) {
            return [];
        }
        /** @var list<ActivityLink> $links */
        $links = $this->em->createQueryBuilder()->select('l')->from(ActivityLink::class, 'l')
            ->where('l.activity IN (:activities)')->setParameter('activities', $activities)
            ->orderBy('l.kind')->addOrderBy('l.targetId')
            ->getQuery()->getResult();
        $byActivity = [];
        foreach ($links as $link) {
            $byActivity[$link->activityId()][] = $link;
        }

        return $byActivity;
    }

    public function add(ActivityType|Activity|ActivityLink $record): void
    {
        $this->em->persist($record);
    }

    public function remove(ActivityLink $link): void
    {
        $this->em->remove($link);
    }
}
