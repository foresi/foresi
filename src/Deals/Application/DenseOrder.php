<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Application;

use Foresi\Deals\Domain\DealLine;
use Foresi\Deals\Domain\LostReason;
use Foresi\Deals\Domain\Stage;

/**
 * A new order of records that are kept in positions 0 to n-1, such as the
 * lines of a deal or the stages: the ids given name every record once.
 */
final class DenseOrder
{
    /**
     * @template T of DealLine|Stage|LostReason
     *
     * @param list<T>     $records
     * @param list<mixed> $ids
     *
     * @return list<T> the records in the order of the ids
     *
     * @throws SalesRefused invalid order_mismatch
     */
    public static function of(array $records, array $ids, string $field, string $message): array
    {
        $byId = [];
        foreach ($records as $record) {
            $byId[$record->id()] = $record;
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || !isset($byId[$id])) {
                throw SalesRefused::invalid('order_mismatch', $field, $message);
            }
            $ordered[$id] = $byId[$id];
        }
        if (\count($ordered) !== \count($byId) || \count($ids) !== \count($byId)) {
            throw SalesRefused::invalid('order_mismatch', $field, $message);
        }

        return array_values($ordered);
    }
}
