<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Purchasing\Application\Query;

use App\Party\Application\LegalEntities;
use App\Purchasing\Application\PurchasingAccess;
use App\Purchasing\Application\PurchasingNotAllowed;
use App\Purchasing\Application\Query\ReceivedDocumentFilter;
use App\Purchasing\Application\Query\ReceivedDocumentList;
use App\Purchasing\Application\Query\ReceivedDocumentQueries;
use App\Purchasing\Domain\PurchaseTaxTreatment;
use App\Purchasing\Domain\ReceivedDocumentKind;
use App\Tests\Purchasing\Application\PurchasingTestCase;

final class ReceivedDocumentListTest extends PurchasingTestCase
{
    public function testOnlyTheStaffReadsTheLists(): void
    {
        $list = $this->list(new ListedQueries());
        $this->signIn($this->guest);

        foreach ([
            fn (): mixed => $list->page(new ReceivedDocumentFilter()),
            $list->drafts(...),
            $list->activeFirms(...),
        ] as $read) {
            try {
                $read();
                self::fail('A guest read the lists.');
            } catch (PurchasingNotAllowed) {
            }
        }
        $this->signIn($this->member);

        self::assertSame(0, $list->drafts());
    }

    public function testTheDraftsCountComesFromTheQueries(): void
    {
        $queries = new ListedQueries();
        $queries->drafts = 7;

        self::assertSame(7, $this->list($queries)->drafts());
    }

    public function testOnlyTheActiveFirmsAreOffered(): void
    {
        $second = $this->firm('Second Firm s.r.o.', '27082440');
        $archived = $this->firm('Old Firm s.r.o.', '25596641');
        $archived->archive($this->clock->now());
        $this->em->flush();

        self::assertSame([
            $this->firm->id() => 'Example Firm s.r.o.',
            $second->id() => 'Second Firm s.r.o.',
        ], $this->list(new ListedQueries())->activeFirms());
    }

    public function testAKnownFirmAndSupplierNarrowTheList(): void
    {
        $queries = new ListedQueries();
        $queries->suppliers = ['supplier-1' => 'Supplier Example s.r.o.'];
        $archived = $this->firm('Old Firm s.r.o.', '25596641');
        $archived->archive($this->clock->now());
        $this->em->flush();

        $page = $this->list($queries)->page(new ReceivedDocumentFilter(ReceivedDocumentFilter::RECORDED, $this->firm->id(), '2026-10', 'supplier-1'));

        self::assertSame($this->firm->id(), $page->filter->firmId);
        self::assertSame('supplier-1', $page->filter->supplierId);
        self::assertSame('2026-10', $page->filter->month);
        self::assertSame(ReceivedDocumentFilter::RECORDED, $page->filter->tab);
        self::assertSame([$this->firm->id(), $archived->id()], array_keys($page->firms), 'Every firm of the organisation, the archived one too.');
        self::assertSame(['supplier-1' => 'Supplier Example s.r.o.'], $page->suppliers);
        self::assertSame($this->firm->id(), $queries->suppliersOf);
        self::assertSame($this->firm->id(), $queries->monthsOf);
    }

    public function testAFirmOrASupplierTheOrganisationDoesNotKnowIsNoFilter(): void
    {
        $queries = new ListedQueries();
        $queries->suppliers = ['supplier-1' => 'Supplier Example s.r.o.'];

        $page = $this->list($queries)->page(new ReceivedDocumentFilter(ReceivedDocumentFilter::DRAFTS, 'other-firm', null, 'other-supplier'));

        self::assertNull($page->filter->firmId);
        self::assertNull($page->filter->supplierId);
        self::assertNull($queries->suppliersOf);
        self::assertNull($queries->monthsOf);
        self::assertNull($queries->filters[0]->firmId);
        self::assertNull($queries->filters[0]->supplierId);
    }

    public function testNoFirmAndNoSupplierStayNone(): void
    {
        $queries = new ListedQueries();
        $queries->suppliers = ['' => 'Nameless'];

        $page = $this->list($queries)->page(new ReceivedDocumentFilter());

        self::assertNull($page->filter->firmId);
        self::assertNull($page->filter->supplierId);
    }

    public function testTheKindAndTheTreatmentPassThrough(): void
    {
        $queries = new ListedQueries();

        $page = $this->list($queries)->page(new ReceivedDocumentFilter(kind: ReceivedDocumentKind::CreditNote, treatment: PurchaseTaxTreatment::EuService));

        self::assertSame(ReceivedDocumentKind::CreditNote, $page->filter->kind);
        self::assertSame(PurchaseTaxTreatment::EuService, $page->filter->treatment);
    }

    public function testTheTextIsCutToItsLimitAndTrimmed(): void
    {
        $queries = new ListedQueries();
        $list = $this->list($queries);

        $long = $list->page(new ReceivedDocumentFilter(text: ' '.str_repeat('é', 120)));
        $padded = $list->page(new ReceivedDocumentFilter(text: "  Supplier\t"));
        $short = $list->page(new ReceivedDocumentFilter(text: 'abc'));

        self::assertSame(str_repeat('é', ReceivedDocumentFilter::MAX_TEXT - 1), $long->filter->text);
        self::assertSame('Supplier', $padded->filter->text);
        self::assertSame('abc', $short->filter->text);
    }

    public function testEveryTabCountsUnderTheSameFilter(): void
    {
        $queries = new ListedQueries();
        $queries->counts = [
            ReceivedDocumentFilter::DRAFTS => 2,
            ReceivedDocumentFilter::RECORDED => 3,
            ReceivedDocumentFilter::TRASH => 1,
        ];
        $list = $this->list($queries);

        foreach (ReceivedDocumentFilter::TABS as $tab) {
            $page = $list->page(new ReceivedDocumentFilter($tab, text: 'x'));

            self::assertSame($queries->counts, $page->tabCounts, $tab);
            self::assertSame($queries->counts[$tab], $page->total, $tab);
        }
    }

    public function testThePagesAreTheTotalByThePageSizeRoundedUpAndNeverFewerThanOne(): void
    {
        foreach ([[0, 1], [1, 1], [25, 1], [26, 2], [50, 2], [51, 3], [60, 3], [250, 10]] as [$total, $pages]) {
            $queries = new ListedQueries();
            $queries->counts = [ReceivedDocumentFilter::DRAFTS => $total];

            $page = $this->list($queries)->page(new ReceivedDocumentFilter());

            self::assertSame($pages, $page->pages, (string) $total);
            self::assertSame($total, $page->total);
            self::assertSame(1, $page->page);
        }
    }

    public function testARowsPageStartsAfterThePagesBefore(): void
    {
        $queries = new ListedQueries();
        $queries->counts = [ReceivedDocumentFilter::DRAFTS => 60];
        $list = $this->list($queries);

        foreach ([[1, 0, 1], [2, 25, 2], [3, 50, 3]] as [$asked, $offset, $shown]) {
            $page = $list->page(new ReceivedDocumentFilter(page: $asked));

            self::assertSame($shown, $page->page);
            self::assertSame([$offset, ReceivedDocumentFilter::PER_PAGE], $queries->lastRange);
        }
    }

    public function testAPagePastTheLastIsTheLastAndOneBeforeTheFirstIsTheFirst(): void
    {
        $queries = new ListedQueries();
        $queries->counts = [ReceivedDocumentFilter::DRAFTS => 60];
        $list = $this->list($queries);

        $past = $list->page(new ReceivedDocumentFilter(page: 99));
        self::assertSame(3, $past->page);
        self::assertSame([50, ReceivedDocumentFilter::PER_PAGE], $queries->lastRange);

        foreach ([0, -4] as $before) {
            $first = $list->page(new ReceivedDocumentFilter(page: $before));
            self::assertSame(1, $first->page);
            self::assertSame([0, ReceivedDocumentFilter::PER_PAGE], $queries->lastRange);
        }
    }

    public function testThePageCarriesTheRowsAndTheMonths(): void
    {
        $queries = new ListedQueries();
        $queries->months = ['2026-11', '2026-10'];

        $page = $this->list($queries)->page(new ReceivedDocumentFilter());

        self::assertSame(['2026-11', '2026-10'], $page->months);
        self::assertSame([], $page->rows);
    }

    private function list(ReceivedDocumentQueries $queries): ReceivedDocumentList
    {
        return new ReceivedDocumentList(
            self::getContainer()->get(PurchasingAccess::class),
            $queries,
            self::getContainer()->get(LegalEntities::class),
        );
    }
}

/**
 * Queries that answer what a test sets, and remember what they were asked.
 */
final class ListedQueries implements ReceivedDocumentQueries
{
    public int $drafts = 0;
    /** @var array<string, int> */
    public array $counts = [];
    /** @var array<string, string> */
    public array $suppliers = [];
    /** @var list<string> */
    public array $months = [];
    public ?string $suppliersOf = 'unasked';
    public ?string $monthsOf = 'unasked';
    /** @var list<ReceivedDocumentFilter> */
    public array $filters = [];
    /** @var array{int, int}|null */
    public ?array $lastRange = null;

    public function count(ReceivedDocumentFilter $filter): int
    {
        $this->filters[] = $filter;

        return $this->counts[$filter->tab] ?? 0;
    }

    public function rows(ReceivedDocumentFilter $filter, int $offset, int $limit): array
    {
        $this->lastRange = [$offset, $limit];

        return [];
    }

    public function drafts(): int
    {
        return $this->drafts;
    }

    public function suppliers(?string $firmId): array
    {
        $this->suppliersOf = $firmId;

        return $this->suppliers;
    }

    public function months(?string $firmId): array
    {
        $this->monthsOf = $firmId;

        return $this->months;
    }

    public function correctable(string $firmId, ?string $exceptId, int $limit): array
    {
        return [];
    }
}
