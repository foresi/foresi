<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Purchasing\Application;

use App\Purchasing\Domain\EvidenceSeries;

/**
 * Gives the evidence numbers of a series. A number is never reused: the counter
 * only goes up, and whoever took a number holds the row until the transaction
 * ends, so a rollback returns the number and nobody else can have it meanwhile.
 *
 * Call next() on a stored series inside a transaction and before anything is
 * read in it: on SQLite, a transaction that read first fails at once when it
 * starts to write.
 */
interface EvidenceNumbers
{
    /**
     * The number next() would give now. Only a preview: writes nothing, holds nothing.
     */
    public function peek(EvidenceSeries $series, \DateTimeImmutable $date): string;

    /**
     * @throws EvidenceNumbersExhausted when the series has no number left; end the transaction
     */
    public function next(EvidenceSeries $series, \DateTimeImmutable $date): string;
}
