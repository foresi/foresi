<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\Candidate;
use App\Banking\Application\Candidates;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Domain\PaymentAllocation;
use App\Shared\Domain\Currency;
use App\Shared\Domain\Money;
use Doctrine\ORM\EntityManagerInterface;

/**
 * One query through the ORM, so the tenant filter narrows the invoices and the
 * payments both. What is paid is summed as the invoice list sums it: amounts and
 * settlements of allocations whose payment is not cancelled.
 */
final readonly class DoctrineCandidates implements Candidates
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function withSymbol(string $firmId, string $variableSymbol): array
    {
        /** @var list<array{id: string, number: string, dueDate: \DateTimeImmutable, issueDate: \DateTimeImmutable, payable: int|numeric-string, currency: Currency, customer: string, paid: int|numeric-string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT i.id AS id, i.number AS number, i.dueDate AS dueDate, i.issueDate AS issueDate, i.payableMinor AS payable, i.currency AS currency, i.customer.name AS customer, '
            .'(SELECT COALESCE(SUM(a.amountMinor + a.settlementMinor), 0) FROM '.PaymentAllocation::class.' a JOIN a.payment p WHERE a.invoice = i AND p.cancelledAt IS NULL) AS paid '
            .'FROM '.Invoice::class.' i '
            ."WHERE i.firm = :firm AND i.deletedAt IS NULL AND TRIM(LEADING '0' FROM i.variableSymbol) = :symbol "
            .'ORDER BY i.dueDate, i.number, i.id',
        )
            ->setParameter('firm', $firmId)
            ->setParameter('symbol', $variableSymbol)
            ->getArrayResult();

        $candidates = [];
        foreach ($rows as $row) {
            // Overpaid invoices have less than nothing left: no candidate either.
            $remaining = (int) $row['payable'] - (int) $row['paid'];
            if ($remaining > 0) {
                $candidates[] = new Candidate($row['id'], $row['number'], $row['dueDate'], Money::ofMinor($remaining, $row['currency']), $row['customer'], $row['issueDate']);
            }
        }

        return $candidates;
    }
}
