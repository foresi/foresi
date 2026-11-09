<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Audit\Domain\AuditEntry;

/**
 * The entries of a log as the steps a person took: the commands save one
 * action under one correlation.
 */
final class AuditSteps
{
    /**
     * Consecutive entries of one correlation make one step; an entry without one is a step of its own.
     *
     * @param list<AuditEntry> $entries
     *
     * @return list<non-empty-list<AuditEntry>>
     */
    public static function of(array $entries): array
    {
        $groups = [];
        $previous = null;
        foreach ($entries as $entry) {
            $key = $entry->correlationId();
            if (null !== $key && $key === $previous) {
                $groups[array_key_last($groups)][] = $entry;
            } else {
                $groups[] = [$entry];
            }
            $previous = $key;
        }

        return $groups;
    }
}
