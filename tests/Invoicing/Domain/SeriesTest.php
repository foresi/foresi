<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Domain;

use Foresi\Invoicing\Contract\DocumentKind;
use Foresi\Invoicing\Domain\Series;
use Foresi\Invoicing\Domain\SeriesFormat;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SeriesTest extends TestCase
{
    public function testAStartAppliesToItsPeriodOnly(): void
    {
        $series = $this->series();

        $series->startAt(90, '2026');

        self::assertSame(90, $series->startFor('2026'));
        self::assertSame(1, $series->startFor('2027'));
    }

    #[TestWith([0, '2026'])]
    #[TestWith([-1, '2026'])]
    #[TestWith([1, '26'])]
    #[TestWith([1, ''])]
    #[TestWith([1, "2026\n"])]
    #[TestWith([2147483648, '2026'])]
    public function testARefusedStart(int $number, string $period): void
    {
        $this->expectException(\DomainException::class);

        $this->series()->startAt($number, $period);
    }

    public function testASeriesWithoutAYearStartsInTheOnePeriodItHas(): void
    {
        $series = $this->series();

        $series->startAt(5, 'all');

        self::assertSame(5, $series->startFor('all'));
    }

    public function testTheDefaultFlagIsTrueOrNull(): void
    {
        $series = $this->series();
        self::assertFalse($series->isDefault());

        $series->markDefault();
        self::assertTrue($series->isDefault());

        $series->clearDefault();
        self::assertFalse($series->isDefault());
    }

    public function testArchivingReleasesTheDefault(): void
    {
        $series = $this->series();
        $series->markDefault();

        $series->archive();

        self::assertTrue($series->isArchived());
        self::assertFalse($series->isDefault());
    }

    public function testNameAndFormatChange(): void
    {
        $series = $this->series();

        $series->rename(' Advance ');
        $series->changeFormat(SeriesFormat::fromString('Z{YY}{NNN}'));

        self::assertSame('Advance', $series->name());
        self::assertSame('Z{YY}{NNN}', $series->format()->toString());
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function testASeriesNeedsAName(string $name): void
    {
        $this->expectException(\DomainException::class);

        $this->series()->rename($name);
    }

    public function testItHasANameAndAnId(): void
    {
        $series = new Series('Standard', SeriesFormat::fromString('{N}'), DocumentKind::Invoice);

        self::assertSame('Standard', $series->name());
        self::assertNotSame('', $series->id());
    }

    private function series(): Series
    {
        return new Series('Standard', SeriesFormat::fromString('{YYYY}{NNNN}'), DocumentKind::Invoice);
    }

    public function testASeriesMayStartAtOne(): void
    {
        $series = new Series('Standard', SeriesFormat::fromString('{NNN}'), DocumentKind::Invoice);

        $series->startAt(1, 'all');

        self::assertSame(1, $series->startFor('all'));
    }
}
