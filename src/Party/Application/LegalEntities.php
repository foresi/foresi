<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\LegalEntity;

/**
 * The firms of the instance. Call add() inside TransactionalWork, which flushes.
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
     * The active firms, by name, without a lock.
     *
     * @return list<LegalEntity>
     */
    public function active(): array;

    /**
     * @throws UnknownLegalEntity also for an unknown firm
     */
    public function get(string $id): LegalEntity;

    /**
     * The firm, its row locked until the transaction ends, so whoever changes
     * what belongs to it waits for the one before. Call it inside TransactionalWork.
     *
     * @throws UnknownLegalEntity also for an unknown firm
     */
    public function lock(string $id): LegalEntity;

    public function add(LegalEntity $firm): void;
}
