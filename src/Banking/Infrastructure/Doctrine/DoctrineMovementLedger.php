<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\MovementLedger;
use Foresi\Banking\Contract\MovementDirection;
use Foresi\Banking\Domain\BankMovement;
use Foresi\Banking\Domain\MovementState;

/**
 * Reads through the entity manager.
 */
final readonly class DoctrineMovementLedger implements MovementLedger
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function bookedAfter(string $accountId, \DateTimeImmutable $after, \DateTimeImmutable $until): array
    {
        $counted = 'm.account = :account AND m.currency = a.currency AND m.state <> :duplicate';
        $row = $this->em->createQuery(
            'SELECT SUM(CASE WHEN m.direction = :incoming THEN m.amountMinor ELSE -m.amountMinor END) AS total, MAX(m.bookedOn) AS lastOn'
            .' FROM '.BankMovement::class.' m JOIN m.account a WHERE '.$counted.' AND m.bookedOn > :after AND m.bookedOn <= :until',
        )
            ->setParameter('account', $accountId)
            ->setParameter('duplicate', MovementState::PossibleDuplicate)
            ->setParameter('incoming', MovementDirection::Incoming)
            ->setParameter('after', $after, Types::DATE_IMMUTABLE)
            ->setParameter('until', $until, Types::DATE_IMMUTABLE)
            ->getSingleResult();
        $total = \is_array($row) && is_numeric($row['total'] ?? null) ? (int) $row['total'] : 0;
        $lastOn = \is_array($row) && \is_string($row['lastOn'] ?? null) ? new \DateTimeImmutable($row['lastOn']) : null;
        $known = $lastOn instanceof \DateTimeImmutable || null !== $this->em->createQuery('SELECT m.id FROM '.BankMovement::class.' m JOIN m.account a WHERE '.$counted)
            ->setParameter('account', $accountId)
            ->setParameter('duplicate', MovementState::PossibleDuplicate)
            ->setMaxResults(1)
            ->getOneOrNullResult();

        return [
            'sumMinor' => $total,
            'lastOn' => $lastOn,
            'known' => $known,
        ];
    }

    public function bookedBetween(string $firmId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        /* @var list<BankMovement> */
        return $this->em->createQuery(
            'SELECT m FROM '.BankMovement::class.' m JOIN m.account a'
            .' WHERE m.firm = :firm AND m.currency = a.currency AND m.state <> :duplicate AND m.bookedOn >= :from AND m.bookedOn <= :to ORDER BY m.bookedOn, m.id',
        )
            ->setParameter('firm', $firmId)
            ->setParameter('duplicate', MovementState::PossibleDuplicate)
            ->setParameter('from', $from, Types::DATE_IMMUTABLE)
            ->setParameter('to', $to, Types::DATE_IMMUTABLE)
            ->getResult();
    }
}
