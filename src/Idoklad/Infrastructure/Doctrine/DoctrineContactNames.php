<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Infrastructure\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Foresi\Idoklad\Application\Import\ContactNames;
use Foresi\Party\Domain\ContactLink;
use Foresi\Party\Domain\Party;

final readonly class DoctrineContactNames implements ContactNames
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function all(): array
    {
        /** @var list<array{id: string, displayName: string}> $rows */
        $rows = $this->em->createQuery(
            'SELECT p.id, p.displayName FROM '.Party::class.' p WHERE p.archivedAt IS NULL'
            .' AND NOT EXISTS (SELECT 1 FROM '.ContactLink::class.' l WHERE l.person = p) ORDER BY p.id',
        )->getArrayResult();

        return array_column($rows, 'displayName', 'id');
    }
}
