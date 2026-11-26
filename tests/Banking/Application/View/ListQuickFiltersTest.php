<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Banking\Application\View;

use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Shared\Application\View\ListDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The quick filters each list of the module pins by default, in their order: what every view
 * that keeps none of its own shows under its tabs.
 */
final class ListQuickFiltersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'bank movements' => ['bank movements', ['direction', 'state']];
    }

    /**
     * @param list<string> $pinned
     */
    #[DataProvider('lists')]
    public function testEachListPinsItsQuickFiltersInOrder(string $list, array $pinned): void
    {
        self::assertSame($pinned, $this->list($list)->pinned);
    }

    private function list(string $list): ListDefinition
    {
        return match ($list) {
            'bank movements' => BankMovementLists::of([]),
            default => throw new \LogicException($list),
        };
    }
}
