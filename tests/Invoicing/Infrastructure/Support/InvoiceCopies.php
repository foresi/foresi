<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Support;

use App\Shared\Domain\Id;
use Doctrine\DBAL\Connection;

/**
 * Many invoice rows at once, copied in SQL from one issued invoice, for tests that
 * need hundreds of candidates without issuing each. Only the invoice row is
 * copied, with a new id and number; its lines are not, so a copy is good for
 * queries over the invoice table and not for anything that renders it.
 */
final class InvoiceCopies
{
    /**
     * @param array<string, int|string> $overrides column values every copy takes instead of the source's
     *
     * @return list<string> the ids of the copies
     */
    public static function of(Connection $connection, string $sourceId, int $count, array $overrides = []): array
    {
        $columns = array_map(static fn (\Doctrine\DBAL\Schema\Column $column): string => $column->getName(), $connection->createSchemaManager()->listTableColumns('invoice'));
        $ids = [];
        for ($copy = 1; $copy <= $count; ++$copy) {
            $id = Id::generate();
            $number = 'C'.substr(str_replace('-', '', $id), -12);
            $values = ['id' => $id, 'number' => $number, 'number_key' => $number] + $overrides;
            $select = array_map(static fn (string $column): string => \array_key_exists($column, $values) ? ':'.$column : $column, $columns);
            $connection->executeStatement(
                \sprintf('INSERT INTO invoice (%s) SELECT %s FROM invoice WHERE id = :source', implode(', ', $columns), implode(', ', $select)),
                ['source' => $sourceId] + array_intersect_key($values, array_flip($columns)),
            );
            $ids[] = $id;
        }

        return $ids;
    }
}
