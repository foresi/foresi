<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\CashFlow\Application\CashFlowStore;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Domain\Checkpoint;
use Foresi\CashFlow\Domain\DocumentPairing;
use Foresi\CashFlow\Domain\ExpectedDate;
use Foresi\CashFlow\Domain\MinimumBalance;
use Foresi\CashFlow\Domain\PlannedItem;
use Foresi\Party\Domain\FirmBankAccount;
use Foresi\Shared\Domain\Id;

/**
 * The records of the cash flow in Doctrine. A write is flushed by the unit of work around it.
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

    public function plannedItems(): array
    {
        /* @var list<PlannedItem> */
        return $this->entityManager->createQueryBuilder()
            ->select('i')->from(PlannedItem::class, 'i')
            ->orderBy('i.createdAt')->addOrderBy('i.id')
            ->getQuery()->getResult();
    }

    public function add(PlannedItem|Checkpoint|ExpectedDate|MinimumBalance|DocumentPairing $record): void
    {
        $this->entityManager->persist($record);
    }

    public function remove(PlannedItem|ExpectedDate|MinimumBalance|DocumentPairing $record): void
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

    public function minimumBalance(): ?MinimumBalance
    {
        $minimum = $this->entityManager->getRepository(MinimumBalance::class)->findOneBy([]);

        return $minimum instanceof MinimumBalance ? $minimum : null;
    }

    public function pairing(string $subjectType, string $subjectId): ?DocumentPairing
    {
        return $this->entityManager->getRepository(DocumentPairing::class)->findOneBy(['subjectType' => $subjectType, 'subjectId' => $subjectId]);
    }

    public function pairings(?PairingState $state = null, ?string $plannedItemId = null): array
    {
        $query = $this->entityManager->createQueryBuilder()->select('p')->from(DocumentPairing::class, 'p');
        if ($state instanceof PairingState) {
            $query->andWhere('p.state = :state')->setParameter('state', $state);
        }
        if (null !== $plannedItemId) {
            $query->andWhere('IDENTITY(p.plannedItem) = :item')->setParameter('item', $plannedItemId);
        }

        /* @var list<DocumentPairing> */
        return $query->orderBy('p.createdAt')->addOrderBy('p.id')->getQuery()->getResult();
    }

    public function pairingsOfOccurrence(string $plannedItemId, \DateTimeImmutable $on): array
    {
        /* @var list<DocumentPairing> */
        return $this->entityManager->createQueryBuilder()
            ->select('p')->from(DocumentPairing::class, 'p')
            ->where('IDENTITY(p.plannedItem) = :item')->andWhere('p.occurrenceOn = :on')
            ->setParameter('item', $plannedItemId)->setParameter('on', $on, Types::DATE_IMMUTABLE)
            ->orderBy('p.createdAt')->addOrderBy('p.id')
            ->getQuery()->getResult();
    }

    public function pairingsOfCounterparty(string $counterpartyId): array
    {
        /* @var list<DocumentPairing> */
        return $this->entityManager->createQueryBuilder()
            ->select('p')->from(DocumentPairing::class, 'p')
            ->where('IDENTITY(p.counterparty) = :counterparty')->setParameter('counterparty', $counterpartyId)
            ->orderBy('p.createdAt')->addOrderBy('p.id')
            ->getQuery()->getResult();
    }

    public function pairedOccurrences(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /* @var list<DocumentPairing> */
        return $this->entityManager->createQueryBuilder()
            ->select('p')->from(DocumentPairing::class, 'p')
            ->where('p.state IN (:states)')->andWhere('p.occurrenceOn >= :from')->andWhere('p.occurrenceOn <= :to')
            ->setParameter('states', [PairingState::Auto, PairingState::Confirmed])
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getQuery()->getResult();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
