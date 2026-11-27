<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\TaxesAndContributions\Infrastructure\Column;

use Foresi\TaxesAndContributions\Contract\StatusKind;

/**
 * A status of the owner as a row of the statuses dialog: its kind, its value as shown, the day it
 * holds from as shown, and the last day it holds, null when it holds until the next change.
 */
final readonly class StatusRow
{
    public function __construct(
        public string $id,
        public StatusKind $kind,
        public string $value,
        public string $from,
        public ?string $until,
    ) {
    }
}
