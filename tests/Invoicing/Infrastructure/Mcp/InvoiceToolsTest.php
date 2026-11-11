<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mcp;

use App\Invoicing\Application\TrashInvoice;
use App\Invoicing\Domain\Invoice;
use App\Invoicing\Infrastructure\Mcp\GetInvoiceTool;
use App\Invoicing\Infrastructure\Mcp\SearchInvoicesTool;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\Cursor;
use App\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foresi_search_invoices and foresi_get_invoice answer what GET /api/v1/invoices
 * and /api/v1/invoices/{id} answer, narrowed by the same filters.
 */
final class InvoiceToolsTest extends ReadToolTestCase
{
    public function testTheSearchDescribesItsArguments(): void
    {
        $tool = $this->tool(SearchInvoicesTool::class);

        self::assertSame('foresi_search_invoices', $tool->name());
        self::assertSame('Search invoices', $tool->title());
        self::assertStringStartsWith('Searches the sales documents of the organisation', $tool->description());
        self::assertStringContainsString('call again with the same arguments and next_cursor as cursor', $tool->description());
        $schema = $tool->inputSchema();
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertArrayNotHasKey('required', $schema);
        self::assertSame(['kind', 'firm_id', 'customer_id', 'state', 'trashed', 'issued_from', 'issued_to', 'query', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame(['invoice', 'advance', 'payment_tax_document', 'correction_document'], self::argument($tool, 'kind')['enum']);
        self::assertSame(['unpaid', 'overdue', 'paid'], self::argument($tool, 'state')['enum']);
        self::assertSame(['type' => 'string', 'format' => 'uuid', 'description' => 'Only documents issued by this firm of the organisation.'], self::argument($tool, 'firm_id'));
        self::assertSame('uuid', self::argument($tool, 'customer_id')['format']);
        self::assertSame(['type' => 'string', 'format' => 'date', 'description' => 'Only documents issued on this day or later. As YYYY-MM-DD, the day included.'], self::argument($tool, 'issued_from'));
        self::assertSame('date', self::argument($tool, 'issued_to')['format']);
        self::assertSame(['type' => 'boolean', 'default' => false], array_intersect_key(self::argument($tool, 'trashed'), ['type' => 0, 'default' => 0]));
        self::assertSame('string', self::argument($tool, 'query')['type']);
        self::assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25], array_intersect_key(self::argument($tool, 'limit'), ['type' => 0, 'minimum' => 0, 'maximum' => 0, 'default' => 0]));
        self::assertSame('string', self::argument($tool, 'cursor')['type']);
    }

    public function testTheGetTakesOneId(): void
    {
        $tool = $this->tool(GetInvoiceTool::class);

        self::assertSame('foresi_get_invoice', $tool->name());
        self::assertSame('Get an invoice', $tool->title());
        self::assertStringStartsWith('Returns one sales document of the organisation by its id', $tool->description());
        self::assertSame([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The id of the document, as foresi_search_invoices returns it.']],
            'required' => ['id'],
            'additionalProperties' => false,
        ], $tool->inputSchema());
    }

    public function testTheSearchAnswersTheItemsOfTheRestList(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice('2026-11-02', '2026-11-30'));

        $output = $this->call(SearchInvoicesTool::class);

        self::assertSame(['items' => $this->rest('/api/v1/invoices'), 'next_cursor' => null], $output->data);
        self::assertSame([$invoice->id()], self::itemIds($output));
        self::assertSame('1 document, newest first; no more match.', $output->summary);
    }

    public function testTheGetAnswersTheRestDetail(): void
    {
        $invoice = $this->inTenant($this->tenant, function (): Invoice {
            $invoice = $this->invoice('2026-11-02', '2026-11-30');
            $this->payment('2026-11-12', [$invoice->id() => '1000']);

            return $invoice;
        });

        $output = $this->call(GetInvoiceTool::class, ['id' => $invoice->id()]);

        self::assertSame($this->rest('/api/v1/invoices/'.$invoice->id()), $output->data);
        self::assertSame('partly_paid', $output->data['payment_state']);
        self::assertSame('The document with its lines, payments, corrections and balances.', $output->summary);
    }

    public function testTheKindTheFirmAndTheCustomerNarrowTheSearch(): void
    {
        [$first, $other, $otherCustomer] = $this->inTenant($this->tenant, function (): array {
            $first = $this->invoice();
            [$firm, $series, $customer] = $this->firmWithCustomer('Other Firm s.r.o.', '25596641');

            return [$first, $this->invoice(firmId: $firm, seriesId: $series, customerId: $customer), $customer];
        });

        self::assertSame([$other->id(), $first->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['kind' => 'invoice'])));
        self::assertSame([], self::itemIds($this->call(SearchInvoicesTool::class, ['kind' => 'advance'])));
        self::assertSame([$first->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['firm_id' => $this->firmId])));
        self::assertSame([$other->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['firm_id' => strtoupper($other->firm()->id())])));
        self::assertSame([$other->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['customer_id' => $otherCustomer])));
        self::assertSame([$first->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['customer_id' => strtoupper($this->customerId)])));
    }

    public function testTheStateAndTheTrashNarrowTheSearch(): void
    {
        [$paid, $overdue, $unpaid, $trashed] = $this->inTenant($this->tenant, function (): array {
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
        [$early, $middle, $late] = $this->inTenant($this->tenant, fn (): array => [
            $this->invoice('2026-11-01', '2026-11-30'),
            $this->invoice('2026-11-05', '2026-11-30'),
            $this->invoice('2026-11-10', '2026-11-30'),
        ]);

        self::assertSame([$late->id(), $middle->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['issued_from' => '2026-11-05'])));
        self::assertSame([$middle->id(), $early->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['issued_to' => '2026-11-05'])));
        self::assertSame([$late->id()], self::itemIds($this->call(SearchInvoicesTool::class, ['query' => ' '.$late->number().' '])));
        $none = $this->call(SearchInvoicesTool::class, ['query' => 'Nothing like it']);
        self::assertSame(['items' => [], 'next_cursor' => null], $none->data);
        self::assertSame('No documents match.', $none->summary);
    }

    public function testTheCursorPagesThroughTheSearchAsTheRestListDoes(): void
    {
        $ids = $this->inTenant($this->tenant, fn (): array => array_map(fn (int $day): string => $this->invoice(\sprintf('2026-11-%02d', $day), '2026-11-30')->id(), [1, 2, 3, 4, 5]));

        self::assertSame([[$ids[4], $ids[3]], [$ids[2], $ids[1]], [$ids[0]]], $this->toolPages(SearchInvoicesTool::class, 2));
        $first = $this->call(SearchInvoicesTool::class, ['limit' => 2]);
        self::assertSame(Cursor::encode($ids[3]), $first->data['next_cursor']);
        self::assertSame('2 documents, newest first; more match: call again with the same arguments and next_cursor as cursor.', $first->summary);
        self::assertSame([$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]], self::itemIds($this->call(SearchInvoicesTool::class)), 'Without a limit, up to 25 are answered.');
        self::assertSame([[$ids[4], $ids[3], $ids[2], $ids[1], $ids[0]]], $this->toolPages(SearchInvoicesTool::class, 5));
        self::assertSame([$ids[2]], self::itemIds($this->call(SearchInvoicesTool::class, ['limit' => 1, 'cursor' => Cursor::encode($ids[3])])));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'a day that is no day' => [['issued_from' => '2026-13-01'], 'The argument "issued_from" cannot be used: This value is not a valid date.'];
        yield 'a day in another format' => [['issued_to' => '1. 11. 2026'], 'The argument "issued_to" cannot be used: This value is not a valid date.'];
        yield 'a limit above the maximum' => [['limit' => 101], 'The argument "limit" cannot be used: This value should be between 1 and 100.'];
        yield 'a limit below one' => [['limit' => 0], 'The argument "limit" cannot be used: This value should be between 1 and 100.'];
        yield 'an unknown kind' => [['kind' => 'receipt'], 'The argument "kind" cannot be used: The value you selected is not a valid choice.'];
        yield 'an unknown state' => [['state' => 'cancelled'], 'The argument "state" cannot be used: The value you selected is not a valid choice.'];
        yield 'a firm that is no id' => [['firm_id' => 'nope'], 'The argument "firm_id" cannot be used: This is not a valid UUID.'];
        yield 'a customer that is no id' => [['customer_id' => 'nope'], 'The argument "customer_id" cannot be used: This is not a valid UUID.'];
        yield 'a cursor no search gave' => [['cursor' => '!!!'], 'The argument "cursor" is not one a search gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.'];
        yield 'a limit that is no integer' => [['limit' => '5'], 'The argument "limit" must be an integer.'];
        yield 'a trash flag that is no boolean' => [['trashed' => 'yes'], 'The argument "trashed" must be true or false.'];
        yield 'a text that is no string' => [['query' => 5], 'The argument "query" must be a string.'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testAnArgumentThatCannotBeUsedIsRefusedByName(array $arguments, string $message): void
    {
        $this->assertRefused('invalid_argument', $message, SearchInvoicesTool::class, $arguments);
    }

    public function testTheLimitsOfTheLimitAreAccepted(): void
    {
        $this->inTenant($this->tenant, fn (): Invoice => $this->invoice());

        self::assertCount(1, self::itemIds($this->call(SearchInvoicesTool::class, ['limit' => 1])));
        self::assertCount(1, self::itemIds($this->call(SearchInvoicesTool::class, ['limit' => 100])));
        self::assertCount(1, self::itemIds($this->call(SearchInvoicesTool::class, ['cursor' => ''])));
    }

    public function testAnInvoiceOfAnotherOrganisationIsNotFound(): void
    {
        $theirs = $this->inTenant($this->foreignTenant(), function (): Invoice {
            [$firm, $series, $customer] = $this->firmWithCustomer();

            return $this->invoice(firmId: $firm, seriesId: $series, customerId: $customer);
        });

        self::assertSame([], self::itemIds($this->call(SearchInvoicesTool::class)));
        foreach ([$theirs->id(), Id::generate(), 'not-an-id', ''] as $id) {
            $this->assertRefused('not_found', 'The organisation has no document with this id. Find the id with foresi_search_invoices.', GetInvoiceTool::class, ['id' => $id]);
        }
        $this->assertRefused('not_found', 'The organisation has no document with this id. Find the id with foresi_search_invoices.', GetInvoiceTool::class);
    }

    public function testAGuestReadsNoInvoices(): void
    {
        $invoice = $this->inTenant($this->tenant, fn (): Invoice => $this->invoice());
        $guest = $this->guest();

        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its invoices.', SearchInvoicesTool::class, [], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its invoices.', GetInvoiceTool::class, ['id' => $invoice->id()], $guest);
    }
}
