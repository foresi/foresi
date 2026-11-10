<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Infrastructure\Doctrine;

use App\Shared\Domain\Id;
use App\Timesheet\Application\ReleasedTimes;
use App\Timesheet\Domain\ReleasedTime;
use Doctrine\ORM\EntityManagerInterface;

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
