<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Application\Query;

use Foresi\Purchasing\Application\Query\ReceivedDocumentFilter;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Purchasing\Domain\PurchaseTaxTreatment;
use Foresi\Purchasing\Domain\ReceivedDocumentKind;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
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

    public function testATabWithoutAViewListsWhatItsDefaultViewHolds(): void
    {
        $lists = ReceivedDocumentLists::list();

        foreach (ReceivedDocumentFilter::TABS as $tab) {
            self::assertSame($lists->default($tab)?->filter->toArray(), new ReceivedDocumentFilter($tab)->view->toArray(), $tab);
        }
        self::assertSame([['field' => 'state', 'op' => 'is', 'value' => 'draft']], new ReceivedDocumentFilter()->view->toArray()['conditions']);
    }

    public function testAViewGivenIsTheViewListed(): void
    {
        $view = ViewFilter::all(new Condition(ReceivedDocumentLists::KIND, 'is', 'credit_note'));
        $counted = ['drafts' => new ViewFilter()];

        $filter = new ReceivedDocumentFilter(ReceivedDocumentFilter::TRASH, 'firm', 'last_month', 'text', 4, $view, $counted);

        self::assertSame($view, $filter->view);
        self::assertSame($counted, $filter->counted);
        self::assertSame('last_month', $filter->month);
    }

    public function testAMonthIsAFilterDateValue(): void
    {
        self::assertNull(ReceivedDocumentFilter::monthValue(null));
        self::assertSame(['month' => '2026-10'], ReceivedDocumentFilter::monthValue('2026-10'));
        foreach (ReceivedDocumentFilter::RELATIVE_MONTHS as $period) {
            self::assertSame(['relative' => $period], ReceivedDocumentFilter::monthValue($period));
        }
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
        yield 'year zero' => ['0999-11', null];
        yield 'this month' => ['this_month', 'this_month'];
        yield 'last month' => ['last_month', 'last_month'];
        yield 'this quarter' => ['this_quarter', 'this_quarter'];
        yield 'this year' => ['this_year', 'this_year'];
        yield 'a period the chip lacks' => ['next_month', null];
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
