<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Support\Registry;

use Foresi\Party\Application\VatRegistry;
use Foresi\Party\Domain\RegistryCheck;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\RegistrySource;
use Foresi\Party\Domain\VatId;
use Psr\Clock\ClockInterface;

/**
 * Test-env stand-in for VIES: CZ12345687 and CZ12345695 are valid, CZ99999999
 * is unavailable, every other VAT id is invalid.
 */
final readonly class FakeVatRegistry implements VatRegistry
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function checkVatId(VatId $vatId): RegistryCheck
    {
        ++RegistryCalls::$count;
        $result = match ($vatId->toString()) {
            'CZ12345687', 'CZ12345695' => RegistryResult::Valid,
            'CZ99999999' => RegistryResult::Unavailable,
            default => RegistryResult::Invalid,
        };

        return new RegistryCheck(RegistrySource::Vies, $result, $this->clock->now());
    }
}
