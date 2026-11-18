<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Party\Infrastructure\Registry;

use Foresi\Party\Domain\RegistrationNumber;
use Foresi\Party\Domain\RegistryResult;
use Foresi\Party\Domain\VatId;
use Foresi\Party\Infrastructure\Registry\CollectedCompanyRegistry;
use Foresi\Party\Infrastructure\Registry\CollectedVatPayerReliability;
use Foresi\Tests\Support\Registry\FakeVatPayerReliability;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Party runs without a country module: with no register installed, lookups
 * come back empty and every payer check is unavailable (ADR 0034, rule 3).
 */
final class CollectedRegistersTest extends TestCase
{
    public function testWithoutARegisterLookupsAreEmpty(): void
    {
        $registry = new CollectedCompanyRegistry([]);

        self::assertNull($registry->lookupByRegistrationNumber(new RegistrationNumber('CZ', '27074358')));
        self::assertSame([], $registry->searchByName('CZ', 'Example', 5));
    }

    public function testWithoutARegisterEveryCheckIsUnavailable(): void
    {
        $reliability = new CollectedVatPayerReliability([], new MockClock());

        self::assertSame(RegistryResult::Unavailable, $reliability->check(VatId::fromString('CZ12345687'))->check->result);
    }

    public function testTheFirstRegisterThatKnowsAPayerAnswers(): void
    {
        $clock = new MockClock();
        $reliability = new CollectedVatPayerReliability([new FakeVatPayerReliability($clock)], $clock);

        $results = $reliability->checkMany([VatId::fromString('CZ12345687'), VatId::fromString('CZ99999999')]);

        self::assertSame(RegistryResult::Unreliable, $results['CZ12345687']->check->result);
        self::assertSame(RegistryResult::Unavailable, $results['CZ99999999']->check->result);
    }
}
