<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Banking\Infrastructure\Doctrine;

use App\Banking\Application\PendingChanges;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A flush: inside a transaction it writes without committing, which the
 * outermost unit of work does.
 */
final readonly class DoctrinePendingChanges implements PendingChanges
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function store(): void
    {
        $this->em->flush();
    }
}
