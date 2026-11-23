<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

use Foresi\Shared\Domain\Money;

/**
 * One recipient of a mapping as the person typed it, with the name of the
 * contact (null for the firm itself).
 */
final readonly class TermView
{
    public function __construct(
        public string $recipient,
        public ?string $recipientName,
        public ?string $percent,
        public ?Money $amountCzk,
        public bool $rest,
    ) {
    }
}
