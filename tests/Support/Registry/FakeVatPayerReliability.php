<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Support\Registry;

use App\Party\Application\ReliabilityResult;
use App\Party\Application\VatPayerReliability;
use App\Party\Domain\RegistryCheck;
use App\Party\Domain\RegistryResult;
use App\Party\Domain\RegistrySource;
use App\Party\Domain\VatId;
use Psr\Clock\ClockInterface;

/**
 * Test-env stand-in for the unreliable payer register: CZ12345679 is
 * unreliable with two published accounts, CZ99999999 is unavailable, every
 * other VAT id is reliable.
 */
final readonly class FakeVatPayerReliability implements VatPayerReliability
{
    public function __construct(private ClockInterface $clock)
    {
    }

    public function check(VatId $vatId): ReliabilityResult
    {
        [$result, $accounts] = match ($vatId->toString()) {
            'CZ12345679' => [RegistryResult::Unreliable, ['2000000011/0800', 'CZ7308000000002000000007']],
            'CZ99999999' => [RegistryResult::Unavailable, []],
            default => [RegistryResult::Reliable, []],
        };

        return new ReliabilityResult(new RegistryCheck(RegistrySource::UnreliablePayer, $result, $this->clock->now()), $accounts);
    }

    public function checkMany(array $vatIds): array
    {
        $results = [];
        foreach ($vatIds as $vatId) {
            $results[$vatId->toString()] = $this->check($vatId);
        }

        return $results;
    }
}
