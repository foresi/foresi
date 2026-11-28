<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Margin\Domain\CostShare;
use Foresi\Shared\Domain\Currency;
use Foresi\Shared\Domain\Money;

/**
 * The stored cost shares the margin table adds up, by month and recipient. No
 * access check of its own: MarginTable and MarginCell ask first.
 */
final readonly class CostShareReads
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * The shares of the months from the first day of $fromMonth to $toMonth,
     * added up per month and recipient ("own", a contact id, or null for the
     * cost nobody has claimed).
     *
     * @return list<array{month: string, recipient: ?string, amount: Money}> month is YYYY-MM
     */
    public function totals(\DateTimeImmutable $fromMonth, \DateTimeImmutable $toMonth): array
    {
        /** @var list<array{month: \DateTimeInterface, recipient: ?string, minor: numeric-string}> $rows */
        $rows = $this->entityManager->createQuery(
            'SELECT s.month AS month, s.recipient AS recipient, SUM(s.amountCzkMinor) AS minor FROM '.CostShare::class.' s WHERE s.month >= :from AND s.month <= :to GROUP BY s.month, s.recipient',
        )
            ->setParameter('from', $fromMonth, 'date_immutable')
            ->setParameter('to', $toMonth, 'date_immutable')
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'month' => $row['month']->format('Y-m'),
            'recipient' => $row['recipient'],
            'amount' => Money::ofMinor((int) $row['minor'], Currency::of('CZK')),
        ], $rows);
    }

    /**
     * How many documents have a share nobody has claimed in the months from
     * the first day of $fromMonth to $toMonth.
     */
    public function unassignedDocuments(\DateTimeImmutable $fromMonth, \DateTimeImmutable $toMonth): int
    {
        return (int) $this->entityManager->createQuery(
            'SELECT COUNT(DISTINCT s.documentId) FROM '.CostShare::class.' s WHERE s.recipient IS NULL AND s.month >= :from AND s.month <= :to',
        )
            ->setParameter('from', $fromMonth, 'date_immutable')
            ->setParameter('to', $toMonth, 'date_immutable')
            ->getSingleScalarResult();
    }

    /**
     * The shares of one recipient in one month; the recipient null is the cost
     * nobody has claimed.
     *
     * @return list<CostShare> by document, then line, so the order is the same on every read
     */
    public function sharesOf(\DateTimeImmutable $month, ?string $recipient): array
    {
        $query = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(CostShare::class, 's')
            ->where('s.month = :month')
            ->setParameter('month', $month, 'date_immutable')
            ->orderBy('s.documentId', 'ASC')
            ->addOrderBy('s.lineKey', 'ASC')
            ->addOrderBy('s.id', 'ASC');
        if (null === $recipient) {
            $query->andWhere('s.recipient IS NULL');
        } else {
            $query->andWhere('s.recipient = :recipient')->setParameter('recipient', $recipient);
        }

        /** @var list<CostShare> $shares */
        $shares = $query->getQuery()->getResult();

        return $shares;
    }
}
