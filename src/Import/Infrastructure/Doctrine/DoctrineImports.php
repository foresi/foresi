<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Doctrine;

use App\Import\Application\Imports;
use App\Import\Domain\Import;
use App\Import\Domain\ImportAmbiguousMatch;
use App\Import\Domain\ImportMissingRecord;
use App\Import\Domain\ImportRow;
use App\Import\Domain\ImportState;
use App\Import\Domain\RowOutcome;
use App\Tenancy\Application\TenantContext;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Reads and writes imports through the entity manager, so the tenant filter
 * confines every query to the active tenant; bulk statements name the import,
 * which was itself found through the filter.
 */
final readonly class DoctrineImports implements Imports
{
    public function __construct(
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
        private TenantContext $context,
    ) {
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

    public function transactional(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $failure) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            // A failed flush closes the manager; the caller must be able to query on, inside the same tenant.
            if (!$this->em->isOpen()) {
                $this->registry->resetManager();
            }
            if ($this->context->has()) {
                $this->context->switchTo($this->context->current());
            } else {
                $this->em->clear();
            }

            throw $failure;
        }
    }

    public function findFresh(int $id): ?Import
    {
        $import = $this->em->find(Import::class, $id);
        if ($import instanceof Import) {
            $this->em->refresh($import);
        }

        return $import;
    }

    public function lockRowsOfChunk(Import $import, int $chunk): array
    {
        return $this->locked(ImportRow::class, $import, $chunk, 'r.rowNumber');
    }

    public function lockMissingOfChunk(Import $import, int $chunk): array
    {
        return $this->locked(ImportMissingRecord::class, $import, $chunk, 'r.id');
    }

    public function hasMissingChunk(Import $import, int $chunk): bool
    {
        return null !== $this->em->getRepository(ImportMissingRecord::class)->findOneBy(['import' => $import, 'chunk' => $chunk]);
    }

    public function resetCheck(Import $import): void
    {
        $this->em->createQueryBuilder()
            ->update(ImportRow::class, 'r')
            ->set('r.outcome', ':none')
            ->set('r.errors', ':empty')
            ->set('r.recordId', ':none')
            ->set('r.matchValue', ':none')
            ->where('r.import = :import')
            ->setParameter('none', null)
            ->setParameter('empty', '[]')
            ->setParameter('import', $import)
            ->getQuery()
            ->execute();
        $this->deleteMissing($import);
        // The bulk update bypassed the loaded rows; let them go so the next read sees the database.
        foreach ($this->em->getUnitOfWork()->getIdentityMap()[ImportRow::class] ?? [] as $row) {
            $this->em->detach($row);
        }
    }

    public function deleteRows(Import $import): void
    {
        $this->em->createQueryBuilder()->delete(ImportRow::class, 'r')->where('r.import = :import')->setParameter('import', $import)->getQuery()->execute();
        $this->deleteMissing($import);
    }

    public function outcomeCounts(Import $import): array
    {
        /** @var list<array{outcome: RowOutcome|string|null, rows: int|string}> $result */
        $result = $this->em->createQueryBuilder()
            ->select('r.outcome AS outcome, COUNT(r.id) AS rows')
            ->from(ImportRow::class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.outcome IS NOT NULL')
            ->groupBy('r.outcome')
            ->setParameter('import', $import)
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($result as $line) {
            $outcome = $line['outcome'] instanceof RowOutcome ? $line['outcome']->value : (string) $line['outcome'];
            $counts[$outcome] = (int) $line['rows'];
        }

        return $counts;
    }

    public function isBusy(string $targetKey, int $exceptImportId): bool
    {
        $count = $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Import::class, 'i')
            ->where('i.targetKey = :target')
            ->andWhere('i.state IN (:states)')
            ->andWhere('i.id <> :except')
            ->setParameter('target', $targetKey)
            ->setParameter('states', [ImportState::Running->value, ImportState::Undoing->value])
            ->setParameter('except', $exceptImportId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
    }

    public function oldestQueued(string $targetKey): ?Import
    {
        return $this->em->getRepository(Import::class)->findOneBy(['targetKey' => $targetKey, 'state' => ImportState::Queued], ['id' => 'ASC']);
    }

    public function rowsWithOutcome(Import $import, RowOutcome $outcome): iterable
    {
        for ($chunk = 0; $chunk < $import->chunks(); ++$chunk) {
            $rows = $this->em->getRepository(ImportRow::class)->findBy(['import' => $import, 'chunk' => $chunk, 'outcome' => $outcome], ['rowNumber' => 'ASC']);
            foreach ($rows as $row) {
                yield $row;
                // Streamed: the rows read are let go, so memory stays flat on a large source.
                $this->em->detach($row);
            }
        }
    }

    public function addAmbiguousMatches(Import $import, array $recordIds): void
    {
        foreach (array_unique($recordIds) as $recordId) {
            $this->em->persist(new ImportAmbiguousMatch($import, $recordId));
        }
    }

    public function findRow(int $id): ?ImportRow
    {
        return $this->em->find(ImportRow::class, $id);
    }

    public function findMissingRecord(int $id): ?ImportMissingRecord
    {
        return $this->em->find(ImportMissingRecord::class, $id);
    }

    public function recordsInSource(Import $import, array $recordIds): array
    {
        if ([] === $recordIds) {
            return [];
        }
        /** @var list<int|string> $found */
        $found = $this->em->createQueryBuilder()
            ->select('DISTINCT r.recordId')
            ->from(ImportRow::class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.recordId IN (:ids)')
            ->setParameter('import', $import)
            ->setParameter('ids', $recordIds)
            ->getQuery()
            ->getSingleColumnResult();

        /** @var list<int|string> $ambiguous */
        $ambiguous = $this->em->createQueryBuilder()
            ->select('DISTINCT m.recordId')
            ->from(ImportAmbiguousMatch::class, 'm')
            ->where('m.import = :import')
            ->andWhere('m.recordId IN (:ids)')
            ->setParameter('import', $import)
            ->setParameter('ids', $recordIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_map(intval(...), [...$found, ...$ambiguous])));
    }

    /**
     * @template T of ImportRow|ImportMissingRecord
     *
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    private function locked(string $class, Import $import, int $chunk, string $order): array
    {
        $result = $this->em->createQueryBuilder()
            ->select('r')
            ->from($class, 'r')
            ->where('r.import = :import')
            ->andWhere('r.chunk = :chunk')
            ->orderBy($order)
            ->setParameter('import', $import)
            ->setParameter('chunk', $chunk)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_values(array_filter(\is_array($result) ? $result : [], static fn (mixed $entity): bool => $entity instanceof $class));
    }

    private function deleteMissing(Import $import): void
    {
        $this->em->createQueryBuilder()->delete(ImportAmbiguousMatch::class, 'a')->where('a.import = :import')->setParameter('import', $import)->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(ImportMissingRecord::class, 'm')->where('m.import = :import')->setParameter('import', $import)->getQuery()->execute();
    }
}
