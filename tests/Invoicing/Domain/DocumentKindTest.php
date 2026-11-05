<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Domain;

use App\Invoicing\Domain\DocumentKind;
use App\Invoicing\Domain\Series;
use App\Invoicing\Domain\SeriesFormat;
use App\Invoicing\Domain\VariableSymbol;
use App\Party\Domain\Address;
use App\Party\Domain\LegalEntity;
use App\Party\Domain\RegistrationNumber;
use App\Party\Domain\VatStatus;
use PHPUnit\Framework\TestCase;

final class DocumentKindTest extends TestCase
{
    public function testEachKindTellsWhatAppliesToIt(): void
    {
        self::assertSame([true, true, true], [DocumentKind::Invoice->isPayable(), DocumentKind::Invoice->isTaxDocument(), DocumentKind::Invoice->isIssuedByPerson()]);
        self::assertSame([true, false, true], [DocumentKind::Advance->isPayable(), DocumentKind::Advance->isTaxDocument(), DocumentKind::Advance->isIssuedByPerson()]);
        self::assertSame([false, true, false], [DocumentKind::PaymentTaxDocument->isPayable(), DocumentKind::PaymentTaxDocument->isTaxDocument(), DocumentKind::PaymentTaxDocument->isIssuedByPerson()]);
    }

    public function testEachKindHasAStandardSeriesOfItsOwn(): void
    {
        $names = array_map(static fn (DocumentKind $kind): string => $kind->standardSeriesName(), DocumentKind::cases());
        $formats = array_map(static fn (DocumentKind $kind): string => $kind->standardSeriesFormat(), DocumentKind::cases());

        self::assertSame(['standard', 'standard_advance', 'standard_payment_tax_document'], $names);
        self::assertSame([Series::STANDARD_FORMAT, 'Z9{YYYY}{NNNN}', 'D8{YYYY}{NNNN}'], $formats);
        self::assertSame(Series::STANDARD, DocumentKind::Invoice->standardSeriesName());
    }

    public function testTheStandardFormatsKeepTheVariableSymbolsApart(): void
    {
        $day = new \DateTimeImmutable('2026-11-05');
        $symbols = array_map(static fn (DocumentKind $kind): string => VariableSymbol::fromNumber(SeriesFormat::fromString($kind->standardSeriesFormat())->render($day, 1)), DocumentKind::cases());

        self::assertSame(['20260001', '920260001', '820260001'], $symbols);
    }

    public function testOnlyTheStandardNamesAreStandard(): void
    {
        self::assertTrue(DocumentKind::isStandardSeriesName('standard'));
        self::assertTrue(DocumentKind::isStandardSeriesName('standard_advance'));
        self::assertTrue(DocumentKind::isStandardSeriesName('standard_payment_tax_document'));
        self::assertFalse(DocumentKind::isStandardSeriesName('Standard'));
        self::assertFalse(DocumentKind::isStandardSeriesName('standard_receipt'));
    }

    public function testTheStandardSeriesIsTheDefaultOfItsKind(): void
    {
        $firm = new LegalEntity('Example Firm s.r.o.', new RegistrationNumber('CZ', '12345695'), null, new Address('Example Street 1', 'Example City', '100 00', 'CZ'), VatStatus::NotRegistered, '');

        $series = Series::standard($firm, DocumentKind::Advance);

        self::assertSame(DocumentKind::Advance, $series->kind());
        self::assertSame('standard_advance', $series->name());
        self::assertSame('Z9{YYYY}{NNNN}', $series->format()->toString());
        self::assertTrue($series->isDefault());
        self::assertSame($firm, $series->firm());
    }
}
