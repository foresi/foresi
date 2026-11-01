<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
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

    public function testItBelongsToItsFirm(): void
    {
        $firm = $this->firm();
        $series = new Series($firm, 'Standard', SeriesFormat::fromString('{N}'), DocumentKind::Invoice);

        self::assertSame($firm, $series->firm());
        self::assertSame($firm, $series->auditRoot());
        self::assertSame('Standard', $series->name());
        self::assertNotSame('', $series->id());
    }

    private function series(): Series
    {
        return new Series($this->firm(), 'Standard', SeriesFormat::fromString('{YYYY}{NNNN}'), DocumentKind::Invoice);
    }

    private function firm(): LegalEntity
    {
        return new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '', []);
    }
}
