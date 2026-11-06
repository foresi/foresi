<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\AdvanceRefunded;
use App\Invoicing\Application\CorrectPaymentTaxDocument;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Corrects the tax documents a refund of an advance reaches, in the transaction of
 * the refund. The refund is stored first, so the payments of the advance count it.
 */
#[AsEventListener]
final readonly class CorrectTaxDocumentsOnRefund
{
    public function __construct(private EntityManagerInterface $em, private CorrectPaymentTaxDocument $corrections)
    {
    }

    public function __invoke(AdvanceRefunded $event): void
    {
        $this->em->flush();
        $this->corrections->afterRefund($event->advanceId);
    }
}
