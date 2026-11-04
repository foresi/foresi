<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Banking\Application;

use App\Banking\Application\BankScreen;
use App\Banking\Application\MovementFilter;
use App\Banking\Application\MovementPage;
use App\Tests\Banking\Support\ScreenStores;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * What the bank screen asks its stores for, and how it pages what they count.
 */
final class BankScreenTest extends TestCase
{
    private const array ACCOUNTS = [
        'account-a' => ['firmId' => 'firm-a', 'firmName' => 'Example Firm s.r.o.', 'label' => 'Main', 'iban' => 'CZ6508000000192000145399'],
        'account-b' => ['firmId' => 'firm-b', 'firmName' => 'Other Firm s.r.o.', 'label' => '', 'iban' => 'CZ5508000000001234567899'],
    ];

    public function testListsTheFirmsAndTheAccountsWithoutTheirFirmNames(): void
    {
        $page = $this->page(new ScreenStores(self::ACCOUNTS, 3, 0, attention: 2), new MovementFilter());

        self::assertSame(['firm-a' => 'Example Firm s.r.o.', 'firm-b' => 'Other Firm s.r.o.'], $page->firms);
        self::assertSame([
            'account-a' => ['firmId' => 'firm-a', 'label' => 'Main', 'iban' => 'CZ6508000000192000145399'],
            'account-b' => ['firmId' => 'firm-b', 'label' => '', 'iban' => 'CZ5508000000001234567899'],
        ], $page->accounts);
        self::assertSame([3, 7, 2], [$page->total, $page->unmatched, $page->attention]);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function filters(): iterable
    {
        // [firm, account] → the firm and account the stores are asked about
        yield 'an account of the firm' => ['firm-a', 'account-a', 'firm-a:account-a'];
        yield 'an account of another firm is no filter' => ['firm-a', 'account-b', 'firm-a:-'];
        yield 'an account without a firm' => [null, 'account-b', '-:account-b'];
        yield 'an account of no one' => [null, 'account-x', '-:-'];
        yield 'a firm of no one' => ['firm-x', 'account-a', '-:account-a'];
    }

    #[DataProvider('filters')]
    public function testAFilterOfNoOneIsNoFilter(?string $firm, ?string $account, string $asked): void
    {
        $stores = new ScreenStores(self::ACCOUNTS, 3, 0);

        $this->page($stores, new MovementFilter(MovementFilter::INCOMING, $firm, $account));

        self::assertSame(['movements:incoming:'.$asked, 'rows:0:25', 'movements:unmatched:'.$asked], $stores->asked);
    }

    public function testTheMessagesTabCountsAndPagesTheMessages(): void
    {
        $stores = new ScreenStores(self::ACCOUNTS, 3, 30);

        $page = $this->page($stores, new MovementFilter(MovementFilter::MESSAGES, page: 2));

        self::assertSame([30, 2, 2], [$page->total, $page->page, $page->pages]);
        self::assertSame(['messages:messages:-:-', 'messageRows:25:25', 'movements:unmatched:-:-'], $stores->asked);
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
