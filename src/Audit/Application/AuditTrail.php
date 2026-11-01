<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Audit\Domain\AuditEntry;

/**
 * Read access to the audit log for features that must know whether an entity
 * changed after a point in time, or show the history of an aggregate.
 */
interface AuditTrail
{
    /**
     * Whether the entity has an entry newer than $since that belongs to none
     * of the given correlations.
     *
     * @param class-string $entityClass
     */
    public function changedSince(string $entityClass, string $entityId, \DateTimeImmutable $since, string ...$exceptCorrelationIds): bool;

    /**
     * Every entry of an aggregate: the root and what belongs to it.
     *
     * @param class-string $rootClass
     *
     * @return list<AuditEntry> oldest first
     */
    public function rootHistory(string $rootClass, string $rootId): array;
}
