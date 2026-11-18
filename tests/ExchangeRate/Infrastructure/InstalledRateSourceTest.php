<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\ExchangeRate\Infrastructure;

use Foresi\ExchangeRate\Infrastructure\InstalledRateSource;
use Foresi\Tests\ExchangeRate\Support\FakeRateSource;
use PHPUnit\Framework\TestCase;

/**
 * Without an installed source there is nothing to fetch, and the daily task's
 * publication time never comes; with one, it answers.
 */
final class InstalledRateSourceTest extends TestCase
{
    public function testWithoutASourceNothingIsEverExpectedOrFetched(): void
    {
        $source = new InstalledRateSource([]);

        self::assertSame(InstalledRateSource::NONE, $source->name());
        self::assertLessThan($source->expectedAt(), '23:59');
        self::assertLessThan($source->settledAt(), '23:59');
        self::assertNull($source->fetch(new \DateTimeImmutable('2026-11-10')));
    }

    public function testTheInstalledSourceAnswers(): void
    {
        $fake = new FakeRateSource();
        $source = new InstalledRateSource([$fake]);

        self::assertSame('fake', $source->name());
        self::assertSame('Europe/Prague', $source->zone()->getName());
        self::assertSame('14:35', $source->expectedAt());
        self::assertSame('24.335', $source->fetch(new \DateTimeImmutable('2026-11-10'))?->quotes['EUR']->rate);
    }
}
