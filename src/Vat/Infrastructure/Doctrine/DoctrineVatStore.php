<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Vat\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Vat\Application\VatStore;
use Foresi\Vat\Domain\PaymentAssignment;
use Foresi\Vat\Domain\PeriodEntry;
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

    public function entry(string $periodKey): ?PeriodEntry
    {
        return $this->entityManager->find(PeriodEntry::class, $periodKey);
    }

    public function entries(): array
    {
        return $this->entityManager->getRepository(PeriodEntry::class)->findBy([], ['periodKey' => 'ASC']);
    }

    public function addEntry(PeriodEntry $entry): void
    {
        $this->entityManager->persist($entry);
    }

    public function removeEntry(PeriodEntry $entry): void
    {
        $this->entityManager->remove($entry);
    }

    public function assignment(string $movementId): ?PaymentAssignment
    {
        return $this->entityManager->find(PaymentAssignment::class, $movementId);
    }

    public function assignments(): array
    {
        return $this->entityManager->getRepository(PaymentAssignment::class)->findBy([], ['movementId' => 'ASC']);
    }

    public function addAssignment(PaymentAssignment $assignment): void
    {
        $this->entityManager->persist($assignment);
    }

    public function removeAssignment(PaymentAssignment $assignment): void
    {
        $this->entityManager->remove($assignment);
    }
}
