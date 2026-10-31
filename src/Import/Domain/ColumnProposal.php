<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

/** The proposed target field of one source column; a null field means "do not import". */
final readonly class ColumnProposal
{
    public function __construct(
        public int $column,
        public ?string $fieldKey,
        public MatchReason $reason,
    ) {
    }
}
