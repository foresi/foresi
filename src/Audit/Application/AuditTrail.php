<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Application;

/**
 * Read access to the audit log for features that must know whether an entity
 * changed after a point in time.
 */
interface AuditTrail
{
    /**
     * Whether the entity has an entry newer than $since that does not belong
     * to the given correlation.
     *
     * @param class-string $entityClass
     */
    public function changedSince(string $entityClass, string $entityId, \DateTimeImmutable $since, string $exceptCorrelationId): bool;
}
