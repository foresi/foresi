<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\TransactionalWork;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineTransactionalWork implements TransactionalWork
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function run(callable $work): mixed
    {
        return $this->em->wrapInTransaction($work);
    }
}
