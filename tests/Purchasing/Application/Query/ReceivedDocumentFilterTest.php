<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application\Query;

use App\Purchasing\Application\Query\ReceivedDocumentFilter;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReceivedDocumentFilterTest extends TestCase
{
    public function testAFilterStartsOnTheDraftsAndTheFirstPage(): void
    {
        $filter = new ReceivedDocumentFilter();

        self::assertSame(ReceivedDocumentFilter::DRAFTS, $filter->tab);
        self::assertSame(1, $filter->page);
        self::assertSame('', $filter->text);
    }

    public function testAnUnknownTabIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ReceivedDocumentFilter('archive');
    }

    public function testAnotherTabKeepsTheNarrowingAndStartsOnTheFirstPage(): void
    {
        $filter = new ReceivedDocumentFilter(ReceivedDocumentFilter::DRAFTS, 'firm', '2026-10', 'supplier', ReceivedDocumentKind::CreditNote, PurchaseTaxTreatment::EuService, 'text', 4);

        $moved = $filter->onTab(ReceivedDocumentFilter::TRASH);

        self::assertSame(ReceivedDocumentFilter::TRASH, $moved->tab);
        self::assertSame(1, $moved->page);
        self::assertSame('firm', $moved->firmId);
        self::assertSame('2026-10', $moved->month);
        self::assertSame('supplier', $moved->supplierId);
        self::assertSame(ReceivedDocumentKind::CreditNote, $moved->kind);
        self::assertSame(PurchaseTaxTreatment::EuService, $moved->treatment);
        self::assertSame('text', $moved->text);
        self::assertSame(4, $filter->page);
    }

    #[DataProvider('months')]
    public function testAMonthIsYearAndMonthAndNothingElse(string $value, ?string $month): void
    {
        self::assertSame($month, ReceivedDocumentFilter::monthOf($value));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function months(): iterable
    {
        yield 'a month' => ['2026-11', '2026-11'];
        yield 'the first' => ['2026-01', '2026-01'];
        yield 'the last' => ['2026-12', '2026-12'];
        yield 'month zero' => ['2026-00', null];
        yield 'month thirteen' => ['2026-13', null];
        yield 'a day' => ['2026-11-01', null];
        yield 'something before' => ['x2026-11', null];
        yield 'something after' => ['2026-11x', null];
        yield 'a line break after' => ["2026-11\n", null];
        yield 'nothing' => ['', null];
    }

    public function testADocumentIsPlacedByItsIssueDateOnlyWhenItComesFromAbroad(): void
    {
        foreach (ReceivedDocumentKind::cases() as $kind) {
            foreach (PurchaseTaxTreatment::cases() as $treatment) {
                $abroad = ReceivedDocumentKind::ForeignInvoice === $kind
                    || \in_array($treatment, [PurchaseTaxTreatment::EuService, PurchaseTaxTreatment::NonEuService], true);

                self::assertSame($abroad, ReceivedDocumentFilter::byIssueDate($kind, $treatment), \sprintf('%s, %s', $kind->value, $treatment->value));
            }
        }
    }

    public function testADomesticDocumentIsPlacedByItsTaxPointAndByItsIssueDateWhileItNamesNone(): void
    {
        $issue = new \DateTimeImmutable('2026-10-28');
        $taxPoint = new \DateTimeImmutable('2026-11-02');

        self::assertSame($taxPoint, ReceivedDocumentFilter::monthDay(ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, $issue, $taxPoint));
        self::assertSame($issue, ReceivedDocumentFilter::monthDay(ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, $issue, null));
        self::assertNull(ReceivedDocumentFilter::monthDay(ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::Domestic, null, null));
    }

    public function testADocumentFromAbroadIsPlacedByItsIssueDate(): void
    {
        $issue = new \DateTimeImmutable('2026-10-28');
        $taxPoint = new \DateTimeImmutable('2026-11-02');

        self::assertSame($issue, ReceivedDocumentFilter::monthDay(ReceivedDocumentKind::ForeignInvoice, PurchaseTaxTreatment::Domestic, $issue, $taxPoint));
        self::assertSame($issue, ReceivedDocumentFilter::monthDay(ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::NonEuService, $issue, $taxPoint));
        self::assertNull(ReceivedDocumentFilter::monthDay(ReceivedDocumentKind::TaxInvoice, PurchaseTaxTreatment::EuService, null, $taxPoint));
    }
}
