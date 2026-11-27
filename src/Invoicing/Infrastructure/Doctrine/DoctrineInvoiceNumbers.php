<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Contract\InvoiceNumbers;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Shared\Domain\Id;

/**
 * The numbers of invoices by their ids, in one query.
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
