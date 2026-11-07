<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Controller;

use App\Invoicing\Infrastructure\Controller\MonthChoices;
use PHPUnit\Framework\TestCase;

final class MonthChoicesTest extends TestCase
{
    public function testMonthsGoFromTheNewestAndReadInTheLocale(): void
    {
        self::assertSame(['2026-11' => 'listopad 2026', '2026-02' => 'únor 2026', '2025-12' => 'prosinec 2025'], MonthChoices::of(['2026-02', '2025-12', '2026-11', '2026-02'], 'cs'));
        self::assertSame(['2026-11' => 'November 2026'], MonthChoices::of(['2026-11'], 'en'));
        self::assertSame([], MonthChoices::of([], 'en'));
    }

    public function testMoreThanThreeYearsOfMonthsAreGroupedByYear(): void
    {
        $months = [];
        for ($month = new \DateTimeImmutable('2023-11-01'); $month <= new \DateTimeImmutable('2026-11-01'); $month = $month->modify('+1 month')) {
            $months[] = $month->format('Y-m');
        }

        self::assertCount(37, $months);
        $choices = MonthChoices::of($months, 'en');

        self::assertSame([2026, 2025, 2024, 2023], array_keys($choices));
        self::assertSame(['2023-12' => 'December 2023', '2023-11' => 'November 2023'], $choices[2023]);
        $latest = $choices[2026];
        self::assertIsArray($latest);
        self::assertSame(['2026-11', '2026-01'], [array_key_first($latest), array_key_last($latest)]);
        self::assertCount(36, MonthChoices::of(\array_slice($months, 1), 'en'));
    }
}
