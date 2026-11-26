<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Infrastructure\Doctrine;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Inbox\Application\ItemStore;
use Foresi\Inbox\Domain\InboxItem;
use Foresi\Inbox\Domain\ItemOutput;
use Foresi\Inbox\Domain\ItemState;
use Foresi\Shared\Domain\Id;

final readonly class DoctrineItemStore implements ItemStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function item(string $id): ?InboxItem
    {
        return Id::isValid($id) ? $this->em->find(InboxItem::class, $id) : null;
    }

    public function itemOfMessage(string $mailMessageId): ?InboxItem
    {
        if (!Id::isValid($mailMessageId)) {
            return null;
        }

        return $this->em->getRepository(InboxItem::class)->findOneBy(['mailMessageId' => $mailMessageId]);
    }

    public function page(string $ownerUserId, ?ItemState $state, bool $unmatched, ?string $afterId, int $limit): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('i')
            ->from(InboxItem::class, 'i')
            ->where('i.ownerUserId = :owner')
            ->andWhere('i.trashedAt IS NULL')
            ->setParameter('owner', $ownerUserId)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults($limit);
        if ($state instanceof ItemState) {
            $query->andWhere('i.state = :state')->setParameter('state', $state);
        }
        if ($unmatched) {
            $query->andWhere('i.contactId IS NULL');
        }
        if (null !== $afterId) {
            $query->andWhere('i.id < :after')->setParameter('after', $afterId);
        }

        /* @var list<InboxItem> */
        return $query->getQuery()->getResult();
    }

    public function countInState(string $ownerUserId, ItemState $state, int $limit): int
    {
        // At most $limit ids are read, so a very full inbox costs no more than the cap of its badge.
        $ids = $this->em->createQueryBuilder()
            ->select('i.id')
            ->from(InboxItem::class, 'i')
            ->where('i.ownerUserId = :owner')
            ->andWhere('i.trashedAt IS NULL')
            ->andWhere('i.state = :state')
            ->setParameter('owner', $ownerUserId)
            ->setParameter('state', $state)
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return \count($ids);
    }

    public function threadSizes(string $ownerUserId, array $threadKeys): array
    {
        if ([] === $threadKeys) {
            return [];
        }
        /** @var list<array{threadKey: string, size: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('i.threadKey AS threadKey', 'COUNT(i.id) AS size')
            ->from(InboxItem::class, 'i')
            ->where('i.ownerUserId = :owner')
            ->andWhere('i.trashedAt IS NULL')
            ->andWhere('i.threadKey IN (:keys)')
            ->groupBy('i.threadKey')
            ->setParameter('owner', $ownerUserId)
            ->setParameter('keys', array_values(array_unique($threadKeys)))
            ->getQuery()
            ->getArrayResult();
        $sizes = [];
        foreach ($rows as $row) {
            $sizes[$row['threadKey']] = (int) $row['size'];
        }

        return $sizes;
    }

    public function threadOf(InboxItem $item): array
    {
        /* @var list<InboxItem> */
        return $this->em->createQueryBuilder()
            ->select('i')
            ->from(InboxItem::class, 'i')
            ->where('i.ownerUserId = :owner')
            ->andWhere('i.threadKey = :key')
            ->andWhere('i.id <> :id')
            ->andWhere('i.trashedAt IS NULL')
            ->orderBy('i.receivedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setParameter('owner', $item->ownerUserId())
            ->setParameter('key', $item->threadKey())
            ->setParameter('id', $item->id())
            ->getQuery()
            ->getResult();
    }

    public function outputs(InboxItem $item): array
    {
        /* @var list<ItemOutput> */
        return $this->em->createQueryBuilder()
            ->select('o')
            ->from(ItemOutput::class, 'o')
            ->where('o.item = :item')
            ->orderBy('o.addedAt', 'ASC')
            ->addOrderBy('o.kind', 'ASC')
            ->addOrderBy('o.recordId', 'ASC')
            ->setParameter('item', $item->id())
            ->getQuery()
            ->getResult();
    }

    public function outputCounts(array $itemIds): array
    {
        if ([] === $itemIds) {
            return [];
        }
        /** @var list<array{itemId: string, size: int|string}> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('IDENTITY(o.item) AS itemId', 'COUNT(o.recordId) AS size')
            ->from(ItemOutput::class, 'o')
            ->where('o.item IN (:items)')
            ->groupBy('o.item')
            ->setParameter('items', $itemIds)
            ->getQuery()
            ->getArrayResult();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['itemId']] = (int) $row['size'];
        }

        return $counts;
    }

    public function trashedBefore(\DateTimeImmutable $time): array
    {
        /* @var list<InboxItem> */
        return $this->em->createQueryBuilder()
            ->select('i')
            ->from(InboxItem::class, 'i')
            ->where('i.trashedAt < :time')
            ->orderBy('i.trashedAt', 'ASC')
            ->setParameter('time', $time->setTimezone(new \DateTimeZone('UTC')))
            ->getQuery()
            ->getResult();
    }

    public function lock(InboxItem $item): void
    {
        $this->em->refresh($item, LockMode::PESSIMISTIC_WRITE);
    }

    public function add(InboxItem|ItemOutput $record): void
    {
        $this->em->persist($record);
    }

    public function remove(InboxItem|ItemOutput $record): void
    {
        $this->em->remove($record);
    }
}
