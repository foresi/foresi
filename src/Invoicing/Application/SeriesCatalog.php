<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Invoicing\Application;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;

/**
 * The number series. Call add() and makeDefault() inside
 * TransactionalWork, which flushes.
 */
interface SeriesCatalog
{
    /**
     * The active series, by name; of one kind when it is given.
     *
     * @return list<Series>
     */
    public function all(?DocumentKind $kind = null): array;

    /**
     * All series, archived ones too, by name. An archived series keeps its
     * name and its numbers, so a new series must not clash with it.
     *
     * @return list<Series>
     */
    public function every(): array;

    /**
     * @throws UnknownSeries
     */
    public function get(string $id): Series;

    /**
     * The default series for a kind, or a new standard one that is not
     * stored yet.
     */
    public function defaultFor(DocumentKind $kind = DocumentKind::Invoice): Series;

    public function add(Series $series): void;

    /**
     * Stores the series at once; the counter refers to it, so it must exist first.
     * Harmless for a stored series.
     */
    public function store(Series $series): void;

    /**
     * Moves the default for the kind of the series to it; stores what is pending first,
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
