<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Timesheet\Application;

/**
 * Why time is not taken to an invoice; the reason is the last part of a
 * translation key under timesheet.refused.
 */
final class TimeInvoicingRefused extends \DomainException
{
    public const array REASONS = ['not_allowed', 'client', 'nothing', 'rate_missing', 'currencies', 'firm_currency', 'country', 'too_many_lines'];

    public function __construct(public readonly string $reason)
    {
        if (!\in_array($reason, self::REASONS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown reason "%s".', $reason));
        }
        parent::__construct(sprintf('The time is not invoiced: %s.', $reason));
    }
}
