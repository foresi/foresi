<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\ExchangeRate\Domain;

/**
 * There is no rate for a currency on a date: the source does not quote the
 * currency, or it could not be reached and nothing is stored for the date.
 */
final class RateNotAvailable extends \RuntimeException
{
    public static function for(string $currency, \DateTimeImmutable $date): self
    {
        return new self(\sprintf('No exchange rate of %s to CZK is available for %s.', $currency, $date->format('Y-m-d')));
    }
}
