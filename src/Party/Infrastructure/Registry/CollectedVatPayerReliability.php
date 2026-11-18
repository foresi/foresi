<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Party\Infrastructure\Registry;

use Foresi\Party\Application\ReliabilityResult;
use Foresi\Party\Application\VatPayerReliability;
use Foresi\Party\Domain\RegistryCheck;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\RegistrySource;
use Foresi\Party\Domain\VatId;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Every register of unreliable VAT payers an installed country module offers.
 * Each knows the VAT ids of its own country and leaves the others unavailable;
 * with none installed, every check is unavailable.
 */
final readonly class CollectedVatPayerReliability implements VatPayerReliability
{
    /**
     * @param iterable<VatPayerReliability> $registers
     */
    public function __construct(
        #[AutowireIterator(VatPayerReliability::TAG)]
        private iterable $registers,
        private ClockInterface $clock,
    ) {
    }

    public function check(VatId $vatId): ReliabilityResult
    {
        return $this->checkMany([$vatId])[$vatId->toString()];
    }

    public function checkMany(array $vatIds): array
    {
        $results = [];
        $open = [];
        foreach ($vatIds as $vatId) {
            $results[$vatId->toString()] = new ReliabilityResult(new RegistryCheck(RegistrySource::UnreliablePayer, RegistryResult::Unavailable, $this->clock->now()));
            $open[$vatId->toString()] = $vatId;
        }

        foreach ($this->registers as $register) {
            if ([] === $open) {
                break;
            }
            foreach ($register->checkMany(array_values($open)) as $key => $result) {
                if (isset($open[$key]) && RegistryResult::Unavailable !== $result->check->result) {
                    $results[$key] = $result;
                    unset($open[$key]);
                }
            }
        }

        return $results;
    }
}
