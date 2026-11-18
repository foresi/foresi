<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Banking\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Banking\Application\PendingChanges;

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
