<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Domain;

use App\Purchasing\Domain\EvidenceNumberFormat;
use App\Purchasing\Domain\InvalidEvidenceFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EvidenceNumberFormatTest extends TestCase
{
    #[DataProvider('rendered')]
    public function testRenders(string $format, string $date, int $sequence, string $number, string $period): void
    {
        $evidence = EvidenceNumberFormat::fromString($format);

        self::assertSame($number, $evidence->render(new \DateTimeImmutable($date), $sequence));
        self::assertSame($period, $evidence->period(new \DateTimeImmutable($date)));
    }

    /**
     * @return iterable<string, array{string, string, int, string, string}>
     */
    public static function rendered(): iterable
    {
        yield 'the standard' => [EvidenceNumberFormat::STANDARD, '2026-11-10', 7, 'R20260007', '2026'];
        yield 'a short year' => ['FP{YY}-{NNN}', '2026-01-01', 12, 'FP26-012', '2026'];
        yield 'never truncated' => ['R{YYYY}{NN}', '2026-11-10', 1234, 'R20261234', '2026'];
        yield 'without a year' => ['IN-{NNNNN}', '2026-11-10', 3, 'IN-00003', EvidenceNumberFormat::ALL_PERIODS];
    }

    public function testTheLongestNumberFitsTheDocument(): void
    {
        self::assertLessThanOrEqual(64, \strlen(EvidenceNumberFormat::fromString(str_repeat('A', 29).'{N}')->longestNumber()));
    }

    public function testRefusesASequenceOutsideTheCounter(): void
    {
        $this->expectException(\RangeException::class);
        EvidenceNumberFormat::standard()->render(new \DateTimeImmutable('2026-11-10'), 0);
    }

    #[DataProvider('invalid')]
    public function testRefuses(string $format): void
    {
        $this->expectException(InvalidEvidenceFormat::class);
        EvidenceNumberFormat::fromString($format);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'no sequence' => ['R{YYYY}'];
        yield 'two sequences' => ['{NN}{NN}'];
        yield 'a month' => ['R{MM}{NNNN}'];
        yield 'a space' => ['R {NNNN}'];
        yield 'an open brace' => ['R{NNNN'];
        yield 'too long' => [str_repeat('A', 30).'{NNNN}'];
        yield 'a sequence too wide' => ['{NNNNNNNNNNN}'];
    }
}
