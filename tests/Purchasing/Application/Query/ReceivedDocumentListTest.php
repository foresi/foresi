<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Application\Query;

use Foresi\Party\Application\InstanceFirm;
use Foresi\Purchasing\Application\PurchasingAccess;
use Foresi\Purchasing\Application\PurchasingNotAllowed;
use Foresi\Purchasing\Application\Query\ReceivedDocumentFilter;
use Foresi\Purchasing\Application\Query\ReceivedDocumentList;
use Foresi\Purchasing\Application\Query\ReceivedDocumentQueries;
use Foresi\Purchasing\Application\Query\ReceivedDocumentSliceFilter;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Shared\Application\View\Condition;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Purchasing\Application\PurchasingTestCase;

final class ReceivedDocumentListTest extends PurchasingTestCase
{
    public function testOnlyTheStaffReadsTheLists(): void
    {
        $list = $this->list(new ListedQueries());
        $this->signIn($this->guest);

        foreach ([
            fn (): mixed => $list->page(new ReceivedDocumentFilter()),
            $list->drafts(...),
            $list->firmId(...),
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

    public function testTheDocumentsGoToTheInstanceFirm(): void
    {
        $second = $this->firm('Second Firm s.r.o.', '27082440');

        self::assertSame($this->firm->id(), $this->list(new ListedQueries())->firmId(), 'The oldest active firm, as InstanceFirm reads it.');

        $this->firm->archive($this->clock->now());
        $this->em->flush();

        self::assertSame($second->id(), $this->list(new ListedQueries())->firmId(), 'An archived firm takes no documents.');

        $second->archive($this->clock->now());
        $this->em->flush();

        self::assertNull($this->list(new ListedQueries())->firmId(), 'Without an active firm, nothing takes documents.');
    }

    public function testThePageKeepsWhatItWasAskedFor(): void
    {
        $queries = new ListedQueries();
        $view = ViewFilter::all(new Condition(ReceivedDocumentLists::KIND, 'is', 'credit_note'));
        $counted = ['drafts' => new ViewFilter()];

        $page = $this->list($queries)->page(new ReceivedDocumentFilter(ReceivedDocumentFilter::RECORDED, '2026-10', view: $view, counted: $counted));

        self::assertSame('2026-10', $page->filter->month);
        self::assertSame(ReceivedDocumentFilter::RECORDED, $page->filter->tab);
        self::assertSame($view, $page->filter->view);
        self::assertSame($counted, $page->filter->counted);
        self::assertTrue($queries->monthsAsked);
    }

    public function testAViewCountsWhatItHoldsOfEveryMonthAndText(): void
    {
        $queries = new ListedQueries();
        $queries->total = 4;
        $view = ViewFilter::all(new Condition(ReceivedDocumentLists::TRASHED, 'is', true));

        self::assertSame(4, $this->list($queries)->count($view));
        self::assertSame($view, $queries->filters[0]->view);
        self::assertSame([], $queries->filters[0]->counted);
        self::assertNull($queries->filters[0]->month);
        self::assertSame('', $queries->filters[0]->text);

        $this->signIn($this->guest);
        $this->expectException(PurchasingNotAllowed::class);
        $this->list($queries)->count($view);
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

    public function testThePageCarriesTheCountsOfTheViews(): void
    {
        $queries = new ListedQueries();
        $queries->total = 3;
        $queries->counts = ['all' => 5, 'drafts' => 2, 'recorded' => 3, 'trash' => 1];

        $page = $this->list($queries)->page(new ReceivedDocumentFilter(ReceivedDocumentFilter::RECORDED, text: 'x'));

        self::assertSame($queries->counts, $page->counts);
        self::assertSame(3, $page->total);
    }

    public function testThePagesAreTheTotalByThePageSizeRoundedUpAndNeverFewerThanOne(): void
    {
        foreach ([[0, 1], [1, 1], [25, 1], [26, 2], [50, 2], [51, 3], [60, 3], [250, 10]] as [$total, $pages]) {
            $queries = new ListedQueries();
            $queries->total = $total;

            $page = $this->list($queries)->page(new ReceivedDocumentFilter());

            self::assertSame($pages, $page->pages, (string) $total);
            self::assertSame($total, $page->total);
            self::assertSame(1, $page->page);
        }
    }

    public function testARowsPageStartsAfterThePagesBefore(): void
    {
        $queries = new ListedQueries();
        $queries->total = 60;
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
        $queries->total = 60;
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
            self::getContainer()->get(InstanceFirm::class),
        );
    }
}

/**
 * Queries that answer what a test sets, and remember what they were asked.
 */
final class ListedQueries implements ReceivedDocumentQueries
{
    public int $drafts = 0;
    public int $total = 0;
    /** @var array<string, int> */
    public array $counts = [];
    /** @var list<string> */
    public array $months = [];
    public bool $monthsAsked = false;
    /** @var list<ReceivedDocumentFilter> */
    public array $filters = [];
    /** @var array{int, int}|null */
    public ?array $lastRange = null;

    public function counts(ReceivedDocumentFilter $filter): array
    {
        $this->filters[] = $filter;

        return [$this->total, $this->counts];
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

    public function months(): array
    {
        $this->monthsAsked = true;

        return $this->months;
    }

    public function correctable(string $firmId, ?string $exceptId, int $limit): array
    {
        return [];
    }

    public function slice(ReceivedDocumentSliceFilter $filter, ?string $afterId, int $limit): array
    {
        return [[], false];
    }
}
