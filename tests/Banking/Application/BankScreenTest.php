<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application;

use Foresi\Banking\Application\BankScreen;
use Foresi\Banking\Application\MovementFilter;
use Foresi\Banking\Application\MovementPage;
use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Shared\Application\View\ViewFilter;
use Foresi\Tests\Banking\Support\ScreenStores;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What the bank screen asks its stores for, and how it pages what they count.
 */
final class BankScreenTest extends TestCase
{
    private const array ACCOUNTS = [
        'account-a' => ['label' => 'Main', 'iban' => 'CZ6508000000192000145399'],
        'account-b' => ['label' => '', 'iban' => 'CZ5508000000001234567899'],
    ];

    public function testListsTheAccounts(): void
    {
        $page = $this->page(new ScreenStores(self::ACCOUNTS, 3, 0, attention: 2), new MovementFilter());

        self::assertSame([
            'account-a' => ['label' => 'Main', 'iban' => 'CZ6508000000192000145399'],
            'account-b' => ['label' => '', 'iban' => 'CZ5508000000001234567899'],
        ], $page->accounts);
        self::assertSame([3, [], false], [$page->total, $page->counts, $page->mail]);
    }

    public function testCountsTheMovementViewsInOneQueryWithinTheTextAndTheMailViewsByWhatWaits(): void
    {
        $stores = new ScreenStores(self::ACCOUNTS, 3, 0, attention: 2);
        $views = $this->views();

        $page = $this->page($stores, new MovementFilter($views['incoming'], 'rent', counted: ['unmatched' => $views['unmatched'], 'messages' => $views['messages'], 'outgoing_unmatched' => $views['outgoing_unmatched']]));

        self::assertSame(['unmatched' => 9, 'outgoing_unmatched' => 9, 'messages' => 2], $page->counts);
        self::assertSame([
            'movements:[{"field":"direction","op":"is","value":"incoming"}]:rent',
            'counts:rent:unmatched,outgoing_unmatched',
            'attention:11:00:[{"field":"bank_mail","op":"is","value":true}]',
            'rows:0:25',
        ], $stores->asked, 'The mail is counted by what waits since an hour before now.');
    }

    public function testCountsNoMovementWhenOnlyTheMailShowsACount(): void
    {
        $stores = new ScreenStores(self::ACCOUNTS, 3, 0, attention: 0);

        $page = $this->page($stores, new MovementFilter(counted: ['messages' => $this->views()['messages']]));

        self::assertSame(['messages' => 0], $page->counts);
        self::assertNotContains('counts:', array_map(static fn (string $asked): string => substr($asked, 0, 7), $stores->asked));
    }

    public function testAViewOfTheMailCountsAndPagesTheMessages(): void
    {
        $stores = new ScreenStores(self::ACCOUNTS, 3, 30);

        $page = $this->page($stores, new MovementFilter($this->views()['messages'], page: 2));

        self::assertSame([30, 2, 2, true, []], [$page->total, $page->page, $page->pages, $page->mail, $page->rows]);
        self::assertSame(['messages:[{"field":"bank_mail","op":"is","value":true}]', 'messageRows:25:25'], $stores->asked);
    }

    /**
     * @return array<string, ViewFilter>
     */
    private function views(): array
    {
        $views = [];
        foreach (BankMovementLists::of([])->defaults as $view) {
            $views[$view->slug] = $view->filter;
        }

        return $views;
    }

    /**
     * @return iterable<string, array{int, int, array{int, int, int}}>
     */
    public static function pages(): iterable
    {
        // [counted, page asked] → [page, pages, offset]
        yield 'nothing is still one page' => [0, 1, [1, 1, 0]];
        yield 'a full page' => [25, 1, [1, 1, 0]];
        yield 'one past a full page' => [26, 2, [2, 2, 25]];
        yield 'just past two pages' => [51, 3, [3, 3, 50]];
        yield 'a page past the last is the last' => [26, 9, [2, 2, 25]];
        yield 'a page before the first is the first' => [26, 0, [1, 2, 0]];
    }

    /**
     * @param array{int, int, int} $expected
     */
    #[DataProvider('pages')]
    public function testPagesWhatItCounts(int $counted, int $asked, array $expected): void
    {
        $stores = new ScreenStores(self::ACCOUNTS, $counted, 0);

        $page = $this->page($stores, new MovementFilter(page: $asked));

        self::assertSame([$expected[0], $expected[1]], [$page->page, $page->pages]);
        self::assertSame('rows:'.$expected[2].':25', $stores->asked[1], 'The offset of the page.');
    }

    private function page(ScreenStores $stores, MovementFilter $filter): MovementPage
    {
        return new BankScreen($stores->movements, $stores->messages, new MockClock('2026-11-20 12:00:00', 'UTC'))->page($filter);
    }
}
