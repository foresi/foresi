<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Timesheet\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Shared\Domain\Id;
use Foresi\Timesheet\Application\ReleasedTimes;
use Foresi\Timesheet\Domain\ReleasedTime;

final readonly class DoctrineReleasedTimes implements ReleasedTimes
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function ofInvoice(string $invoiceId): array
    {
        return Id::isValid($invoiceId) ? $this->entityManager->getRepository(ReleasedTime::class)->findBy(['invoiceId' => $invoiceId], ['entryId' => 'ASC']) : [];
    }

    public function add(ReleasedTime $time): void
    {
        $this->entityManager->persist($time);
    }

    public function remove(ReleasedTime $time): void
    {
        $this->entityManager->remove($time);
    }
}
