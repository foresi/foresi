<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Application;

use App\Import\Domain\RowError;

/** One source row read through the mapping: its values, what is wrong with it and its match key. */
final readonly class RowEvaluation
{
    /**
     * @param array<string, mixed> $values     normalised by field key; null for an empty cell
     * @param list<RowError>       $errors
     * @param string|null          $matchValue the fingerprint of the match key value; null when it is empty or unreadable
     * @param string               $keyText    the cell text of the match key, for messages
     */
    public function __construct(
        public array $values,
        public array $errors,
        public ?string $matchValue,
        public string $keyText,
    ) {
    }
}
