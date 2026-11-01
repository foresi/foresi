<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

use App\Invoicing\Domain\Series;

/**
 * Gives the numbers of a series. A number is never reused: the counter only goes
 * up, and whoever took a number holds the row until the transaction ends, so a
 * rollback returns the number and nobody else can have it meanwhile.
 *
 * Call next() and advanceTo() on a stored series, inside TransactionalWork, and
 * before anything is read in that transaction: on SQLite, a transaction that read
 * first fails at once when it starts to write.
 */
interface NumberCounter
{
    /**
     * The number next() would give now. Only a preview: writes nothing, holds nothing.
     */
    public function peek(Series $series, \DateTimeImmutable $date): string;

    /**
     * @throws SequenceOutOfRange when the series has no number left; end the transaction
     */
    public function next(Series $series, \DateTimeImmutable $date): string;

    /**
     * Moves the counter up to a number given elsewhere; never down.
     *
     * @throws SequenceOutOfRange when the counter cannot hold the sequence
     */
    public function advanceTo(Series $series, \DateTimeImmutable $date, int $sequence): void;

    /**
     * The last sequence given in a period, 0 before the first.
     */
    public function last(Series $series, string $period): int;
}
