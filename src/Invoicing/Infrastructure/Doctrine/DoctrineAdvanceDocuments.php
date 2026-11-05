<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\AdvanceDocuments;
use App\Invoicing\Domain\Invoice;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineAdvanceDocuments implements AdvanceDocuments
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function taxDocumentOf(string $advanceId, string $paymentId): ?string
    {
        $id = $this->em->createQuery('SELECT i.id FROM '.Invoice::class.' i WHERE i.advanceInvoiceId = :advance AND i.paymentId = :payment')
            ->setParameter('advance', $advanceId)
            ->setParameter('payment', $paymentId)
            ->getOneOrNullResult();

        return \is_array($id) && \is_string($id['id'] ?? null) ? $id['id'] : null;
    }
}
