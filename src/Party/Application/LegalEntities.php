<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Application;

use App\Party\Domain\LegalEntity;

/**
 * The firms of the active tenant. Call add() inside TransactionalWork, which flushes.
 */
interface LegalEntities
{
    /**
     * Active and archived, by name.
     *
     * @return list<LegalEntity>
     */
    public function all(): array;

    /**
     * @throws UnknownLegalEntity also for a firm of another tenant
     */
    public function get(string $id): LegalEntity;

    /**
     * The active firms, locked until the transaction ends. Call it inside TransactionalWork.
     *
     * @return list<LegalEntity>
     */
    public function lockActive(): array;

    public function add(LegalEntity $firm): void;
}
