<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Infrastructure\Doctrine;

use App\Invoicing\Application\FirmInvoicingSettings;
use App\Invoicing\Domain\FirmInvoicing;
use App\Party\Domain\LegalEntity;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFirmInvoicingSettings implements FirmInvoicingSettings
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function for(LegalEntity $firm): FirmInvoicing
    {
        $stored = $this->em->createQuery('SELECT i FROM '.FirmInvoicing::class.' i WHERE i.firm = :firm')
            ->setParameter('firm', $firm->id())
            ->getOneOrNullResult();

        return $stored instanceof FirmInvoicing ? $stored : FirmInvoicing::standard($firm);
    }

    public function save(FirmInvoicing $settings): void
    {
        $this->em->persist($settings);
    }
}
