<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Integration\Ui\Column;

/**
 * A VAT status of the firm as a row of its VAT tab: the label of the status, the day it holds
 * from and the last day it holds, each as shown, null when it holds from the start or until
 * the next change, and whether it is the status of today.
 */
final readonly class VatStatusRow
{
    public function __construct(
        public string $id,
        public string $label,
        public ?string $from,
        public ?string $until,
        public bool $current,
    ) {
    }
}
