<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Integration\Country;

use Foresi\Invoicing\Application\TrashInvoice;
use Foresi\Invoicing\Domain\Invoice;
use Foresi\Invoicing\Infrastructure\Mcp\GetInvoiceTool;
use Foresi\Invoicing\Infrastructure\Mcp\SearchInvoicesTool;
use Foresi\Shared\Infrastructure\Api\Cursor;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;

/**
 * foresi_search_invoices and foresi_get_invoice answer what GET /api/v1/invoices
 * and /api/v1/invoices/{id} answer, narrowed by the same filters.
 */
final class InvoiceToolsTest extends ReadToolTestCase
{
    use IssuesInvoices;

    public function testTheSearchAnswersTheItemsOfTheRestList(): void
    {
        $invoice = $this->flushed(fn (): Invoice => $this->invoice('2026-11-02', '2026-11-30'));

        $output = $this->call(SearchInvoicesTool::class);

        self::assertSame(['items' => $this->rest('/api/v1/invoices'), 'next_cursor' => null, 'applied_view' => null], $output->data);
        self::assertSame([$invoice->id()], self::itemIds($output));
        self::assertSame('1 document, newest first; no more match.', $output->summary);
    }

    public function testTheGetAnswersTheRestDetail(): void
    {
        $invoice = $this->flushed(function (): Invoice {
            $invoice = $this->invoice('2026-11-02', '2026-11-30');
            $this->payment('2026-11-12', [$invoice->id() => '1000']);

            return $invoice;
        });

        $output = $this->call(GetInvoiceTool::class, ['id' => $invoice->id()]);

        self::assertSame($this->rest('/api/v1/invoices/'.$invoice->id()), $output->data);
        self::assertSame('partly_paid', $output->data['payment_state']);
        self::assertSame('The document with its lines, payments, corrections and balances.', $output->summary);
    }

    public function testTheKindAndTheCustomerNarrowTheSearch(): void
    {
        [$first, $other, $otherCustomer] = $this->flushed(function (): array {
            $first = $this->invoice();
            $customer = $this->otherCustomer();

            return [$first, $this->invoice(customerId: $customer), $customer];
        });

        self::assertSame([$other->id(), $first->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['kind' => 'invoice'])));
        self::assertSame([], self::itemIds($this->call(SearchInvoicesTool::class, ['kind' => 'advance'])));
        self::assertSame([$other->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['customer_id' => $otherCustomer])));
        self::assertSame([$first->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['customer_id' => strtoupper($this->customerId)])));
    }

    public function testTheStateAndTheTrashNarrowTheSearch(): void
    {
        [$paid, $overdue, $unpaid, $trashed] = $this->flushed(function (): array {
            $paid = $this->invoice('2026-11-01', '2099-12-31');
            $this->payment('2026-11-05', [$paid->id() => '2420']);
            $overdue = $this->invoice('2020-01-01', '2020-01-15');
            $unpaid = $this->invoice('2026-11-01', '2099-12-31');
            $trashed = $this->invoice('2026-11-01', '2099-12-31');
            self::getContainer()->get(TrashInvoice::class)->trash($trashed->id(), 'Wrong customer');

            return [$paid, $overdue, $unpaid, $trashed];
        });

        self::assertSame([$paid->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['state' => 'paid'])));
        self::assertSame([$overdue->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['state' => 'overdue'])));
        self::assertSame([$unpaid->id(), $overdue->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['state' => 'unpaid'])));
        self::assertSame([$unpaid->id(), $overdue->id(), $paid->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['trashed' => false])));
        self::assertSame([$trashed->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['trashed' => true])));
    }

    public function testTheIssueDaysAndTheTextNarrowTheSearch(): void
    {
        [$early, $middle, $late] = $this->flushed(fn (): array => [
            $this->invoice('2026-11-01', '2026-11-30'),
            $this->invoice('2026-11-05', '2026-11-30'),
            $this->invoice('2026-11-10', '2026-11-30'),
        ]);

        self::assertSame([$late->id(), $middle->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['issued_from' => '2026-11-05'])));
        self::assertSame([$middle->id(), $early->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['issued_to' => '2026-11-05'])));
        self::assertSame([$late->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['query' => ' '.$late->number().' '])));
        $none = $this->call(SearchInvoicesTool::class, ['query' => 'Nothing like it']);
        self::assertSame(['items' => [], 'next_cursor' => null, 'applied_view' => null], $none->data);
        self::assertSame('No documents match.', $none->summary);
    }

    public function testTheCursorPagesThroughTheSearchAsTheRestListDoes(): void
    {
        $ids = $this->flushed(fn (): array => array_map(fn (int $day): string => $this->invoice(\sprintf('2026-11-%02d', $day), '2026-11-30')->id(), [1, 2, 3, 4, 5]));

        self::assertSame([[$ids[4], $ids[3]], [$ids[2], $ids[1]], [$ids[0]]], $this->toolPages(SearchInvoicesTool::class, 2));
        $first = $this->call(SearchInvoicesTool::class, ['limit' => 2]);
        self::assertSame(Cursor::encode($ids[3]), $first->data['next_cursor']);
        self::assertSame('2 documents, newest first; more match: call again with the same arguments and next_cursor as cursor.', $first->summary);
        self::assertSame([$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]], self::itemIds($this->call(SearchInvoicesTool::class)), 'Without a limit, up to 25 are answered.');
        self::assertSame([[$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]]], $this->toolPages(SearchInvoicesTool::class, 5));
        self::assertSame([$ids[2]], self::itemIds($this->call(SearchInvoicesTool::class, ['limit' => 1, 'cursor' => Cursor::encode($ids[3])])));
    }

    public function testTheLimitsOfTheLimitAreAccepted(): void
    {
        $this->flushed(fn (): Invoice => $this->invoice());

        self::assertCount(1, self::itemIds($this->call(SearchInvoicesTool::class, ['limit' => 1])));
        self::assertCount(1, self::itemIds($this->call(SearchInvoicesTool::class, ['limit' => 100])));
        self::assertCount(1, self::itemIds($this->call(SearchInvoicesTool::class, ['cursor' => ''])));
    }

    public function testAGuestReadsNoInvoices(): void
    {
        $invoice = $this->flushed(fn (): Invoice => $this->invoice());
        $guest = $this->guest();

        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its invoices.', SearchInvoicesTool::class, [], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its invoices.', GetInvoiceTool::class, ['id' => $invoice->id()], $guest);
    }
}
