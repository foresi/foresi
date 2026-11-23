<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Sales\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Sales\Application\DealFilter;
use Foresi\Sales\Application\SalesStore;
use Foresi\Sales\Domain\Deal;
use Foresi\Sales\Domain\DealContact;
use Foresi\Sales\Domain\DealLine;
use Foresi\Sales\Domain\DealLink;
use Foresi\Sales\Domain\DealStatus;
use Foresi\Sales\Domain\LostReason;
use Foresi\Sales\Domain\Stage;
use Foresi\Shared\Domain\Id;

/**
 * The records of Sales in Doctrine. Adding and removing write at once, inside
 * the unit of work around them, so what a use case reads next sees them.
 */
final readonly class DoctrineSalesStore implements SalesStore
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function deal(string $id): ?Deal
    {
        return Id::isValid($id) ? $this->em->find(Deal::class, $id) : null;
    }

    public function deals(DealFilter $filter): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('d')
            ->from(Deal::class, 'd')
            ->where('d.trashedAt IS NULL')
            ->orderBy('d.createdAt', 'DESC')
            ->addOrderBy('d.id', 'DESC');
        if ($filter->status instanceof DealStatus) {
            $query->andWhere('d.status = :status')->setParameter('status', $filter->status->value);
        }
        foreach (['stageId' => $filter->stageId, 'ownerUserId' => $filter->ownerUserId, 'clientPartyId' => $filter->clientPartyId] as $field => $value) {
            if (null !== $value) {
                $query->andWhere(\sprintf('d.%s = :%s', $field, $field))->setParameter($field, $value);
            }
        }
        if (null !== $filter->text) {
            $query->andWhere("LOWER(d.title) LIKE :text ESCAPE '!'")
                ->setParameter('text', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filter->text)).'%');
        }
        /** @var list<Deal> $deals */
        $deals = $query->getQuery()->getResult();

        return $deals;
    }

    public function openDealsIn(string $stageId): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Deal::class, 'd')
            ->where('d.stageId = :stage AND d.status = :open AND d.trashedAt IS NULL')
            ->setParameter('stage', $stageId)
            ->setParameter('open', DealStatus::Open->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function linesOf(string $dealId): array
    {
        /** @var list<DealLine> $lines */
        $lines = $this->em->getRepository(DealLine::class)->findBy(['dealId' => $dealId], ['position' => 'ASC', 'id' => 'ASC']);

        return $lines;
    }

    public function contactsOf(string $dealId): array
    {
        /** @var list<DealContact> $contacts */
        $contacts = $this->em->getRepository(DealContact::class)->findBy(['dealId' => $dealId], ['position' => 'ASC']);

        return $contacts;
    }

    public function linksOf(string $dealId): array
    {
        /** @var list<DealLink> $links */
        $links = $this->em->createQueryBuilder()
            ->select('l')
            ->from(DealLink::class, 'l')
            ->where('l.fromDealId = :deal OR l.toDealId = :deal')
            ->setParameter('deal', $dealId)
            ->orderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $links;
    }

    public function stage(string $id): ?Stage
    {
        return Id::isValid($id) ? $this->em->find(Stage::class, $id) : null;
    }

    public function stageByKey(string $key): ?Stage
    {
        return $this->em->getRepository(Stage::class)->findOneBy(['key' => $key]);
    }

    public function stages(bool $includeArchived = false): array
    {
        /** @var list<Stage> $stages */
        $stages = $this->ordered(Stage::class, $includeArchived);

        return $stages;
    }

    public function lostReason(string $id): ?LostReason
    {
        return Id::isValid($id) ? $this->em->find(LostReason::class, $id) : null;
    }

    public function lostReasonByKey(string $key): ?LostReason
    {
        return $this->em->getRepository(LostReason::class)->findOneBy(['key' => $key]);
    }

    public function lostReasons(bool $includeArchived = false): array
    {
        /** @var list<LostReason> $reasons */
        $reasons = $this->ordered(LostReason::class, $includeArchived);

        return $reasons;
    }

    public function add(Deal|DealLine|DealContact|DealLink|Stage|LostReason $record): void
    {
        $this->em->persist($record);
        $this->em->flush();
    }

    public function remove(DealLine|DealContact|DealLink $record): void
    {
        $this->em->remove($record);
        $this->em->flush();
    }

    /**
     * @param class-string<Stage|LostReason> $class
     *
     * @return list<object>
     */
    private function ordered(string $class, bool $includeArchived): array
    {
        $query = $this->em->createQueryBuilder()
            ->select('r')
            ->addSelect('CASE WHEN r.archivedAt IS NULL THEN 0 ELSE 1 END AS HIDDEN archivedLast')
            ->from($class, 'r')
            ->orderBy('archivedLast', 'ASC')
            ->addOrderBy('r.position', 'ASC')
            ->addOrderBy('r.id', 'ASC');
        if (!$includeArchived) {
            $query->where('r.archivedAt IS NULL');
        }
        /** @var list<object> $records */
        $records = $query->getQuery()->getResult();

        return $records;
    }
}
