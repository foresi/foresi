<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Console;

use App\Import\Application\ImportGate;
use App\Import\Application\Imports;
use App\Import\Domain\Import;
use App\Import\Domain\ImportState;
use App\Tenancy\Application\TenantContext;
use App\Tenancy\Domain\TenantId;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fails imports that stopped moving. A worker that died for good leaves its import
 * in Reading, Checking, Running or Undoing, and a Running or Undoing one keeps the
 * target away from the imports queued behind it. An import that has changed
 * neither state nor counters for STALL_SECONDS is marked Failed with
 * "import.failure.stalled", and the next queued import of the target starts.
 *
 * It does not re-send lost chunk messages: the Doctrine transport has no reliable
 * way to tell which chunks of one import are still waiting, and a run is not
 * safe to guess at. The import fails and can be uploaded again.
 *
 * It works across all tenants, so it finds the imports through the connection and
 * then switches to the tenant of each one.
 */
#[AsCommand(name: 'app:import:health', description: 'Fail imports that stopped moving and start the imports queued behind them')]
final readonly class ImportHealthCommand
{
    /** Twice the redeliver_timeout of the async transport (7200 s): a message still being handled is redelivered after one. */
    public const int STALL_SECONDS = 14400;

    private const array WORKING = [ImportState::Reading, ImportState::Checking, ImportState::Running, ImportState::Undoing];

    public function __construct(
        private Connection $connection,
        private TenantContext $tenants,
        private Imports $imports,
        private ImportGate $gate,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $cutoff = $now->modify(\sprintf('-%d seconds', self::STALL_SECONDS));

        /** @var list<array{id: int|string, tenant_id: string}> $stalled */
        $stalled = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id FROM import WHERE state IN (?) AND COALESCE(progressed_at, started_at, created_at) < ? ORDER BY id',
            [array_map(static fn (ImportState $state): string => $state->value, self::WORKING), $cutoff],
            [ArrayParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );

        $failed = 0;
        try {
            foreach ($stalled as $row) {
                $this->tenants->switchTo(TenantId::fromString($row['tenant_id']));
                $failed += $this->fail((int) $row['id'], $now, $cutoff);
            }
        } finally {
            $this->tenants->reset();
        }

        $io->success(\sprintf('Failed %d stalled imports.', $failed));

        return Command::SUCCESS;
    }

    /**
     * @return int 1 when the import was failed, 0 when it moved on in the meantime
     */
    private function fail(int $id, \DateTimeImmutable $now, \DateTimeImmutable $cutoff): int
    {
        $target = $this->imports->transactional(function () use ($id, $now, $cutoff): ?string {
            $import = $this->imports->findLocked($id);
            // Re-checked under the lock: a chunk may have finished since the query.
            if (!$import instanceof Import || !\in_array($import->state(), self::WORKING, true) || $import->progressedAt() >= $cutoff) {
                return null;
            }
            $import->fail('import.failure.stalled', $now);

            return $import->targetKey();
        });
        if (null === $target) {
            return 0;
        }
        $this->gate->release($target);

        return 1;
    }
}
