<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Support;

use Foresi\ExchangeRate\Contract\RateRules;

final readonly class FakeRateRules implements RateRules
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(private string $country, private array $allowed, private string $default)
    {
    }

    public function country(): string
    {
        return $this->country;
    }

    public function allowedSources(): array
    {
        return $this->allowed;
    }

    public function defaultSource(): string
    {
        return $this->default;
    }
}
