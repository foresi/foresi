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
        return $this->em->find(Import::class, $id, LockMode::PESSIMISTIC_WRITE);
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
