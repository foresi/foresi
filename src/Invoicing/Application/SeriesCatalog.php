<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Series;
use App\Party\Domain\LegalEntity;

/**
 * The number series of the active tenant. Call add() and makeDefault() inside
 * TransactionalWork, which flushes.
 */
interface SeriesCatalog
{
    /**
     * The active series of a firm, by name.
     *
     * @return list<Series>
     */
    public function forFirm(LegalEntity $firm): array;

    /**
     * @throws UnknownSeries also for a series of another tenant
     */
    public function get(string $id): Series;

    /**
     * The default series of a firm, or a new one that is not stored yet.
     */
    public function defaultFor(LegalEntity $firm): Series;

    public function add(Series $series): void;

    /**
     * Moves the default of the firm to this series; stores what is pending first,
     * because the unique index must never see two defaults.
     */
    public function makeDefault(Series $series): void;

    /**
     * Whether a number of this series was given. A document always takes its
     * number here, and a rolled-back one leaves nothing behind.
     */
    public function hasDocuments(Series $series): bool;
}
