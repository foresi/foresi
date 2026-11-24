<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * One version of a supplier's default: the month it applies from (YYYY-MM),
 * the recipient ("own" or a contact id) or null where it ends the default,
 * and whether it is the version in force this month.
 */
final readonly class SupplierDefaultVersionView
{
    public function __construct(
        public string $fromMonth,
        public ?string $recipient,
        public ?string $recipientName,
        public bool $current,
    ) {
    }
}
