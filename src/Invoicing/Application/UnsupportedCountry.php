<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

final class UnsupportedCountry extends \DomainException
{
    public static function for(string $countryCode): self
    {
        return new self(\sprintf('No invoicing rules for country "%s".', $countryCode));
    }
}
