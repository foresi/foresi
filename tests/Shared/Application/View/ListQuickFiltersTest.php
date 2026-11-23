<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Banking\Application\View\BankMovementLists;
use Foresi\Catalog\Application\View\ProductLists;
use Foresi\Invoicing\Application\View\RecurringLists;
use Foresi\Invoicing\Application\View\SendLogLists;
use Foresi\Invoicing\Application\View\TaxDocumentQueueLists;
use Foresi\Party\Application\View\ContactLists;
use Foresi\Purchasing\Application\View\ReceivedDocumentLists;
use Foresi\Sales\Application\View\DealLists;
use Foresi\Shared\Application\View\ListDefinition;
use Foresi\Work\Application\View\MyTaskLists;
use Foresi\Work\Application\View\NotificationLists;
use Foresi\Work\Application\View\ProjectLists;
use Foresi\Work\Application\View\ProjectTaskLists;
use Foresi\Work\Application\View\TimeReportLists;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The quick filters each list pins by default, in their order: what every view that keeps none
 * of its own shows under its tabs.
 */
final class ListQuickFiltersTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'bank movements' => [BankMovementLists::class, ['direction', 'state']];
        yield 'products' => [ProductLists::class, ['billing_kind']];
        yield 'recurring invoices' => [RecurringLists::class, ['state', 'next']];
        yield 'sends' => [SendLogLists::class, ['status', 'when']];
        yield 'advances missing a tax document' => [TaxDocumentQueueLists::MISSING, ['deadline']];
        yield 'tax documents uncorrected' => [TaxDocumentQueueLists::UNCORRECTED, ['deadline', 'deducted']];
        yield 'contacts' => [ContactLists::class, ['roles', 'kind']];
        yield 'received documents' => [ReceivedDocumentLists::class, ['period', 'supplier']];
        yield 'deals' => [DealLists::class, ['status', 'owner']];
        yield 'my tasks' => [MyTaskLists::class, ['client']];
        yield 'notifications' => [NotificationLists::class, ['reason', 'unread']];
        yield 'projects' => [ProjectLists::class, ['client', 'billable']];
        yield 'project tasks' => [ProjectTaskLists::class, ['responsible', 'due']];
        yield 'time report' => [TimeReportLists::class, ['worked_on', 'person']];
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
            BankMovementLists::class => BankMovementLists::of([]),
            TaxDocumentQueueLists::MISSING => TaxDocumentQueueLists::missing(),
            TaxDocumentQueueLists::UNCORRECTED => TaxDocumentQueueLists::uncorrected(),
            MyTaskLists::class => MyTaskLists::list(),
            TimeReportLists::class => TimeReportLists::list(),
            ProductLists::class => self::getContainer()->get(ProductLists::class)->list(),
            RecurringLists::class => self::getContainer()->get(RecurringLists::class)->list(),
            SendLogLists::class => self::getContainer()->get(SendLogLists::class)->list(),
            ContactLists::class => self::getContainer()->get(ContactLists::class)->list(),
            ReceivedDocumentLists::class => self::getContainer()->get(ReceivedDocumentLists::class)->list(),
            DealLists::class => self::getContainer()->get(DealLists::class)->list(),
            NotificationLists::class => self::getContainer()->get(NotificationLists::class)->list(),
            ProjectLists::class => self::getContainer()->get(ProjectLists::class)->list(),
            ProjectTaskLists::class => self::getContainer()->get(ProjectTaskLists::class)->list(),
            default => throw new \LogicException($list),
        };
    }
}
