<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Inbox\Application\SuggestionStore;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\Suggestion;
use Foresi\Inbox\Domain\SuggestionState;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineSuggestionStore implements SuggestionStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(string $id): ?Suggestion
    {
        return Id::isValid($id) ? $this->em->find(Suggestion::class, $id) : null;
    }

    public function forItem(InboxItem $item): array
    {
        /* @var list<Suggestion> */
        return $this->em->createQueryBuilder()
            ->select('s', 'step')
            ->from(Suggestion::class, 's')
            ->leftJoin('s.steps', 'step')
            ->where('s.item = :item')
            ->orderBy('s.proposedAt', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->addOrderBy('step.position', 'ASC')
            ->setParameter('item', $item->id())
            ->getQuery()
            ->getResult();
    }

    public function openCounts(array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        /** @var list<array{itemId: string, size: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(s.item) AS itemId', 'COUNT(s.id) AS size')
            ->from(Suggestion::class, 's')
            ->where('s.item IN (:items)')
            ->andWhere('s.state = :proposed')
            ->groupBy('s.item')
            ->setParameter('items', $itemIds)
            ->setParameter('proposed', SuggestionState::Proposed)
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['itemId']] = (int) $row['size'];
        }

        return $counts;
    }

    public function lockForUpdate(Suggestion $suggestion): void
    {
        // The steps are refreshed with it (cascade refresh).
        $this->em->refresh($suggestion, LockMode::PESSIMISTIC_WRITE);
    }

    public function add(Suggestion $suggestion): void
    {
        $this->em->persist($suggestion);
    }

    public function remove(Suggestion $suggestion): void
    {
        $this->em->remove($suggestion);
    }
}
