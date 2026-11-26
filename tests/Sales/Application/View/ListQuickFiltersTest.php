<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Sales\Application\View;

use Foresi\Sales\Application\View\DealLists;
use Foresi\Shared\Application\View\ListDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The quick filters each list of the module pins by default, in their order: what every view
 * that keeps none of its own shows under its tabs.
 */
final class ListQuickFiltersTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'deals' => ['deals', ['stage', 'owner']];
    }

    /**
     * @param list<string> $pinned
     */
    #[DataProvider('lists')]
    public function testEachListPinsItsQuickFiltersInOrder(string $list, array $pinned): void
    {
        self::assertSame($pinned, $this->list($list)->pinned);
    }

    public function testAListIsDeclaredOnce(): void
    {
        $deals = self::getContainer()->get(DealLists::class);

        self::assertSame($deals->list(), $deals->list());
    }

    private function list(string $list): ListDefinition
    {
        return match ($list) {
            'deals' => self::getContainer()->get(DealLists::class)->list(),
            default => throw new \LogicException($list),
        };
    }
}
