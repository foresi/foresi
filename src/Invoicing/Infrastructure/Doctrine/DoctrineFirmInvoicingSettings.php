<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Domain\FirmInvoicing;
use Foresi\Party\Domain\LegalEntity;

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
