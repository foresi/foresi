<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Domain;

use Foresi\ExchangeRate\Contract\PublishedRates;
use Foresi\ExchangeRate\Contract\Quote;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;

/**
 * A publication names the currency it is priced in; a source written before
 * module API 1.1 that names none is priced in CZK, with a deprecation.
 */
final class PublishedRatesTest extends TestCase
{
    public function testThePublicationKeepsItsBase(): void
    {
        $published = new PublishedRates(new \DateTimeImmutable('2026-11-25'), ['USD' => new Quote('USD', 10935, '10000')], 'EUR');

        self::assertSame('EUR', $published->base);
    }

    #[IgnoreDeprecations]
    public function testWithoutABaseItIsCzkAndDeprecated(): void
    {
        $this->expectUserDeprecationMessage('Since foresi/exchange-rate 0.11: Creating "Foresi\ExchangeRate\Contract\PublishedRates" without a base currency is deprecated since module API 1.1, pass "CZK" as the base.');

        self::assertSame('CZK', new PublishedRates(new \DateTimeImmutable('2026-11-25'), [])->base);
    }

    public function testABaseIsAnUpperCaseCode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PublishedRates(new \DateTimeImmutable('2026-11-25'), [], 'eur');
    }
}
