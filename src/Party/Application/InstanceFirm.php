<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Application;

use Foresi\Party\Domain\LegalEntity;

/**
 * The one firm of the instance (ADR 0039): the legal entity that issues its
 * documents and names the organisation. There is none before the first-run
 * setup, and a firm can be created only then.
 *
 * Until the multi-firm screens and data are removed, a database may still hold
 * several firms (tests, old development data); the firm is then the oldest
 * one by its time-ordered id, which is what the fallbacks used before.
 */
final readonly class InstanceFirm
{
    public function __construct(private LegalEntities $firms)
    {
    }

    /**
     * Null before the first-run setup.
     */
    public function find(): ?LegalEntity
    {
        $firms = $this->firms->all();
        usort($firms, static fn (LegalEntity $a, LegalEntity $b): int => strcmp($a->id(), $b->id()));

        return $firms[0] ?? null;
    }

    /**
     * @throws NoFirmYet before the first-run setup
     */
    public function get(): LegalEntity
    {
        return $this->find() ?? throw new NoFirmYet();
    }

    /**
     * The first-run setup. Call it inside TransactionalWork, which flushes.
     *
     * @throws FirmAlreadyExists when the instance has a firm
     */
    public function create(LegalEntity $firm): void
    {
        if ([] !== $this->firms->all()) {
            throw new FirmAlreadyExists();
        }
        $this->firms->add($firm);
    }
}
