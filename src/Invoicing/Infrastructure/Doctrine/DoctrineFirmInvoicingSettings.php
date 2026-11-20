<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Invoicing\Application\FirmInvoicingSettings;
use Foresi\Invoicing\Domain\FirmInvoicing;

final readonly class DoctrineFirmInvoicingSettings implements FirmInvoicingSettings
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function get(): FirmInvoicing
    {
        $stored = $this->em->getRepository(FirmInvoicing::class)->findOneBy([]);

        return $stored instanceof FirmInvoicing ? $stored : FirmInvoicing::standard();
    }

    public function save(FirmInvoicing $settings): void
    {
        $this->em->persist($settings);
    }
}
