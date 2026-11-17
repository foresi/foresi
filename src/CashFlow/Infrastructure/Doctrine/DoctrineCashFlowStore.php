<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\CashFlow\Infrastructure\Doctrine;

use App\CashFlow\Application\CashFlowStore;
use App\CashFlow\Domain\Checkpoint;
use App\CashFlow\Domain\ExpectedDate;
use App\CashFlow\Domain\MinimumBalance;
use App\CashFlow\Domain\PlannedItem;
use App\Party\Domain\FirmBankAccount;
use App\Shared\Domain\Id;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The records of the cash flow in Doctrine. The tenant filter keeps every
 * query in the active tenant. A write is flushed by the unit of work around it.
 */
final readonly class DoctrineCashFlowStore implements CashFlowStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function plannedItem(string $id): ?PlannedItem
    {
        return Id::isValid($id) ? $this->entityManager->find(PlannedItem::class, $id) : null;
    }

    public function plannedItemsOf(string $firmId): array
    {
        if (!Id::isValid($firmId)) {
            return [];
        }

        /* @var list<PlannedItem> */
        return $this->entityManager->createQueryBuilder()
            ->select('i')->from(PlannedItem::class, 'i')
            ->where('IDENTITY(i.firm) = :firm')->setParameter('firm', $firmId)
            ->orderBy('i.createdAt')->addOrderBy('i.id')
            ->getQuery()->getResult();
    }

    public function add(PlannedItem|Checkpoint|ExpectedDate|MinimumBalance $record): void
    {
        $this->entityManager->persist($record);
    }

    public function remove(PlannedItem|ExpectedDate|MinimumBalance $record): void
    {
        $this->entityManager->remove($record);
    }

    public function checkpoint(FirmBankAccount $account, \DateTimeImmutable $on): ?Checkpoint
    {
        return $this->entityManager->getRepository(Checkpoint::class)->findOneBy(['account' => $account, 'on' => $on]);
    }

    public function latestCheckpoint(FirmBankAccount $account, \DateTimeImmutable $on): ?Checkpoint
    {
        $checkpoint = $this->entityManager->createQueryBuilder()
            ->select('c')->from(Checkpoint::class, 'c')
            ->where('c.account = :account')->andWhere('c.on <= :on')
            ->setParameter('account', $account)->setParameter('on', $on, Types::DATE_IMMUTABLE)
            ->orderBy('c.on', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $checkpoint instanceof Checkpoint ? $checkpoint : null;
    }

    public function expectedDate(string $subjectType, string $subjectId): ?ExpectedDate
    {
        return $this->entityManager->getRepository(ExpectedDate::class)->findOneBy(['subjectType' => $subjectType, 'subjectId' => $subjectId]);
    }

    public function expectedDates(): array
    {
        $dates = [];
        foreach ($this->entityManager->getRepository(ExpectedDate::class)->findAll() as $expected) {
            $dates[$expected->subjectType().':'.$expected->subjectId()] = $expected->expectedOn();
        }

        return $dates;
    }

    public function minimumBalance(string $firmId): ?MinimumBalance
    {
        if (!Id::isValid($firmId)) {
            return null;
        }

        $minimum = $this->entityManager->createQueryBuilder()
            ->select('m')->from(MinimumBalance::class, 'm')
            ->where('IDENTITY(m.firm) = :firm')->setParameter('firm', $firmId)
            ->getQuery()->getOneOrNullResult();

        return $minimum instanceof MinimumBalance ? $minimum : null;
    }
}
