<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Calendar\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Calendar\Application\CalendarStore;
use Foresi\Calendar\Domain\CalendarFeed;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineCalendarStore implements CalendarStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function feed(string $id): ?CalendarFeed
    {
        return Id::isValid($id) ? $this->entityManager->find(CalendarFeed::class, $id) : null;
    }

    public function feedByTokenHash(string $tokenHash): ?CalendarFeed
    {
        return $this->entityManager->getRepository(CalendarFeed::class)->findOneBy(['tokenHash' => $tokenHash]);
    }

    public function feedsOf(string $userId): array
    {
        /* @var list<CalendarFeed> */
        return $this->entityManager->createQueryBuilder()
            ->select('f')->from(CalendarFeed::class, 'f')
            ->where('f.userId = :user')->setParameter('user', $userId)
            ->orderBy('f.createdAt')->addOrderBy('f.id')
            ->getQuery()->getResult();
    }

    public function add(CalendarFeed $feed): void
    {
        $this->entityManager->persist($feed);
    }

    public function save(): void
    {
        $this->entityManager->flush();
    }

    public function transaction(callable $work): mixed
    {
        if ($this->entityManager->getConnection()->isTransactionActive()) {
            $result = $work();
            $this->entityManager->flush();

            return $result;
        }

        return $this->entityManager->wrapInTransaction($work);
    }
}
