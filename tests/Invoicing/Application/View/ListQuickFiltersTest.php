<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Application\View;

use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Invoicing\Application\View\SendLogLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
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
        yield 'recurring invoices' => ['recurring invoices', ['state', 'next']];
        yield 'sends' => ['sends', ['status', 'when']];
        yield 'advances missing a tax document' => ['advances missing a tax document', ['deadline']];
        yield 'tax documents uncorrected' => ['tax documents uncorrected', ['deadline', 'deducted']];
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
            'recurring invoices' => self::getContainer()->get(RecurringLists::class)->list(),
            'sends' => self::getContainer()->get(SendLogLists::class)->list(),
            'advances missing a tax document' => TaxDocumentQueueLists::missing(),
            'tax documents uncorrected' => TaxDocumentQueueLists::uncorrected(),
            default => throw new \LogicException($list),
        };
    }
}
