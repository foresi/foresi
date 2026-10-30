<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Import\Application\Imports;
use App\Import\Domain\Import;
use App\Import\Domain\ImportRow;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineImports implements Imports
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function add(Import $import): void
    {
        $this->em->persist($import);
        $this->em->flush();
    }

    public function save(): void
    {
        $this->em->flush();
    }

    public function find(int $id): ?Import
    {
        return $this->em->find(Import::class, $id);
    }

    public function findLocked(int $id): ?Import
    {
        $import = $this->em->find(Import::class, $id);
        if (!$import instanceof Import) {
            return null;
        }
        // An import already in the identity map keeps its stale counters on find(), even with a lock; refresh reads them under the lock.
        $this->em->refresh($import, LockMode::PESSIMISTIC_WRITE);

        return $import;
    }

    public function addRows(array $rows): void
    {
        foreach ($rows as $row) {
            $this->em->persist($row);
        }
        $this->em->flush();
    }

    public function rowsOfChunk(Import $import, int $chunk): array
    {
        return $this->em->getRepository(ImportRow::class)->findBy(['import' => $import, 'chunk' => $chunk], ['rowNumber' => 'ASC']);
    }
}
