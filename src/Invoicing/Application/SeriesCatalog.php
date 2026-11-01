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
     * All series of a firm, archived ones too, by name. An archived series keeps its
     * name and its numbers, so a new series must not clash with it.
     *
     * @return list<Series>
     */
    public function everyForFirm(LegalEntity $firm): array;

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
     * Stores the series at once; the counter refers to it, so it must exist first.
     * Harmless for a stored series.
     */
    public function store(Series $series): void;

    /**
     * Moves the default of the firm to this series; stores what is pending first,
     * because the unique index must never see two defaults.
     */
    public function makeDefault(Series $series): void;

    /**
     * Whether the series gave a number: its counter has moved, through next() or
     * advanceTo(), which a rollback undoes too, or an invoice stands in it, as one
     * with a number typed by hand in another form does without reaching the counter.
     * An invoice in the trash counts.
     */
    public function hasDocuments(Series $series): bool;
}
