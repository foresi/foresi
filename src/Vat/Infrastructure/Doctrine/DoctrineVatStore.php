<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Vat\Application\VatStore;
use Foresi\Vat\Domain\VatSettings;

/**
 * The records of the VAT module in Doctrine. A write is flushed by the unit
 * of work around it.
 */
final readonly class DoctrineVatStore implements VatStore
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function settings(): ?VatSettings
    {
        return $this->entityManager->find(VatSettings::class, VatSettings::ID);
    }

    public function add(VatSettings $settings): void
    {
        $this->entityManager->persist($settings);
    }
}
