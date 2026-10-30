<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Registry;

use App\Party\Application\VatRegistry;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use Psr\Clock\ClockInterface;

/**
 * Test-env stand-in for VIES: CZ12345679 and CZ12345687 are valid, CZ99999999
 * is unavailable, every other VAT id is invalid.
 */
final readonly class FakeVatRegistry implements VatRegistry
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function checkVatId(VatId $vatId): RegistryCheck
    {
        $result = match ($vatId->toString()) {
            'CZ12345679', 'CZ12345687' => RegistryResult::Valid,
            'CZ99999999' => RegistryResult::Unavailable,
            default => RegistryResult::Invalid,
        };

        return new RegistryCheck(RegistrySource::Vies, $result, $this->clock->now());
    }
}
