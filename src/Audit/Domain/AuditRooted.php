<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Domain;

/**
 * An audited entity that belongs to an aggregate: its audit entries are
 * recorded under the root, so one query returns the whole history of the
 * root, children removed since included. An audited entity without this
 * interface is its own root.
 */
interface AuditRooted
{
    /**
     * The audited entity the history of this one is part of.
     */
    public function auditRoot(): object;
}
