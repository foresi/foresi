<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * Something the table cannot count yet, by its code (KnownGaps::MISSING_EXCHANGE_RATE)
 * with the ids of the documents it concerns.
 */
final readonly class KnownGap
{
    /**
     * @param list<string> $documentIds
     */
    public function __construct(public string $code, public array $documentIds)
    {
    }
}
