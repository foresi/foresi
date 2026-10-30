<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Infrastructure\Console;

use App\Import\Application\ImportFiles;
use App\Import\Domain\ImportState;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes the source file and the staged rows of imports that ended more than
 * 30 days ago. The Import record and its counters stay. It works across all
 * tenants, so it reads through the connection and not through the tenant-scoped
 * entity manager.
 */
#[AsCommand(name: 'app:import:purge', description: 'Delete the files and staged rows of imports that ended more than 30 days ago')]
final readonly class PurgeImportsCommand
{
    public const int RETENTION_DAYS = 30;

    public function __construct(
        private Connection $connection,
        private ImportFiles $files,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $cutoff = $this->clock->now()->modify(\sprintf('-%d days', self::RETENTION_DAYS));
        $ended = array_map(
            static fn (ImportState $state): string => $state->value,
            array_values(array_filter(ImportState::cases(), static fn (ImportState $state): bool => $state->isEnd())),
        );

        /** @var list<array{id: int|string, file_key: string|null}> $imports */
        $imports = $this->connection->fetchAllAssociative(
            'SELECT i.id, i.file_key FROM import i'
            .' WHERE i.state IN (?) AND i.finished_at < ?'
            .' AND (i.file_key IS NOT NULL OR EXISTS (SELECT 1 FROM import_row r WHERE r.import_id = i.id))'
            .' ORDER BY i.id',
            [$ended, $cutoff],
            [ArrayParameterType::STRING, Types::DATETIME_IMMUTABLE],
        );

        $rows = 0;
        $files = 0;
        foreach ($imports as $import) {
            $id = (int) $import['id'];
            // The file goes first and deleting is idempotent: a failure below leaves the key in place for the next run.
            if (null !== $import['file_key']) {
                $this->files->delete($import['file_key']);
                ++$files;
            }
            $rows += $this->connection->transactional(function (Connection $connection) use ($id): int {
                $deleted = $connection->executeStatement('DELETE FROM import_row WHERE import_id = ?', [$id]);
                $connection->executeStatement('UPDATE import SET file_key = NULL WHERE id = ?', [$id]);

                return (int) $deleted;
            });
        }

        $io->success(\sprintf('Purged %d imports: %d files and %d rows deleted.', \count($imports), $files, $rows));

        return Command::SUCCESS;
    }
}
