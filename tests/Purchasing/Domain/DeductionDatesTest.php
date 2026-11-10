<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Domain;

use App\Purchasing\Domain\DeductionDates;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeductionDatesTest extends TestCase
{
    #[DataProvider('dates')]
    public function testDates(PurchaseTaxTreatment $treatment, ?string $taxPoint, ?string $received, ?string $periodStart, ?string $latest): void
    {
        $dates = DeductionDates::of($treatment, $this->day($taxPoint), $this->day($received));

        self::assertSame($periodStart, $dates->periodStart?->format('Y-m-d'));
        self::assertSame($latest, $dates->latestDate?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{PurchaseTaxTreatment, ?string, ?string, ?string, ?string}>
     */
    public static function dates(): iterable
    {
        // The example of the tax administration: delivered in May, received in July.
        yield 'received after the tax point' => [PurchaseTaxTreatment::Domestic, '2025-05-26', '2025-07-03', '2025-07-01', '2027-12-31'];
        yield 'received before the tax point' => [PurchaseTaxTreatment::Domestic, '2026-03-02', '2026-02-27', '2026-03-01', '2028-12-31'];
        yield 'the deadline counts from the tax point' => [PurchaseTaxTreatment::Domestic, '2026-12-31', '2027-01-04', '2027-01-01', '2028-12-31'];
        yield 'self-assessed in the month of the tax point' => [PurchaseTaxTreatment::NonEuService, '2026-10-31', '2026-11-03', '2026-10-01', null];
        yield 'self-assessed without the document' => [PurchaseTaxTreatment::EuService, '2026-10-15', null, '2026-10-01', null];
        yield 'domestic reverse charge' => [PurchaseTaxTreatment::DomesticReverseCharge, '2026-10-15', '2026-11-20', '2026-10-01', null];
        yield 'domestic without the document' => [PurchaseTaxTreatment::Domestic, '2026-10-15', null, null, null];
        yield 'without a tax point' => [PurchaseTaxTreatment::Domestic, null, '2026-10-15', null, null];
        yield 'non-deductible' => [PurchaseTaxTreatment::NonDeductible, '2026-10-15', '2026-10-15', null, null];
        yield 'no VAT document' => [PurchaseTaxTreatment::NotVatDocument, '2026-10-15', '2026-10-15', null, null];
    }

    private function day(?string $date): ?\DateTimeImmutable
    {
        return null === $date ? null : new \DateTimeImmutable($date);
    }
}
