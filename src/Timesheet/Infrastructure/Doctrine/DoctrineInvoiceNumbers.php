<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Infrastructure\Doctrine;

use App\Invoicing\Domain\Invoice;
use App\Shared\Domain\Id;
use App\Work\Application\InvoiceNumbers;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The numbers of the invoices that billed time, for Work to name them, in one
 * query under the tenant filter.
 */
final readonly class DoctrineInvoiceNumbers implements InvoiceNumbers
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function numbersOf(array $invoiceIds): array
    {
        $ids = array_values(array_filter($invoiceIds, Id::isValid(...)));
        if ([] === $ids) {
            return [];
        }
        /** @var list<array{id: string, number: string}> $rows */
        $rows = $this->entityManager->createQuery('SELECT i.id, i.number FROM '.Invoice::class.' i WHERE i.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getArrayResult();

        return array_column($rows, 'number', 'id');
    }
}
