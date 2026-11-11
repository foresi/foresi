<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Invoicing\Infrastructure\Mcp;

use App\Invoicing\Domain\Payment;
use App\Invoicing\Domain\PaymentDirection;
use App\Invoicing\Infrastructure\Mcp\GetPaymentTool;
use App\Invoicing\Infrastructure\Mcp\SearchPaymentsTool;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Api\Cursor;
use App\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foresi_search_payments and foresi_get_payment answer what GET /api/v1/payments
 * and /api/v1/payments/{id} answer, narrowed by the same filters.
 */
final class PaymentToolsTest extends ReadToolTestCase
{
    public function testTheSearchDescribesItsArguments(): void
    {
        $tool = $this->tool(SearchPaymentsTool::class);

        self::assertSame('foresi_search_payments', $tool->name());
        self::assertSame('Search payments', $tool->title());
        self::assertStringStartsWith('Searches the payments of the organisation\'s sales documents', $tool->description());
        self::assertStringContainsString('call again with the same arguments and next_cursor as cursor', $tool->description());
        $schema = $tool->inputSchema();
        self::assertSame('object', $schema['type']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame(['firm_id', 'invoice_id', 'direction', 'received_from', 'received_to', 'include_cancelled', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame('uuid', self::argument($tool, 'firm_id')['format']);
        self::assertSame('uuid', self::argument($tool, 'invoice_id')['format']);
        self::assertSame(['incoming', 'refund'], self::argument($tool, 'direction')['enum']);
        self::assertSame('date', self::argument($tool, 'received_from')['format']);
        self::assertSame('date', self::argument($tool, 'received_to')['format']);
        self::assertSame('boolean', self::argument($tool, 'include_cancelled')['type']);
        self::assertSame(100, self::argument($tool, 'limit')['maximum']);
    }

    public function testTheGetTakesOneId(): void
    {
        $tool = $this->tool(GetPaymentTool::class);

        self::assertSame('foresi_get_payment', $tool->name());
        self::assertSame('Get a payment', $tool->title());
        self::assertStringStartsWith('Returns one payment of the organisation by its id', $tool->description());
        self::assertSame([
            'type' => 'object',
            'properties' => ['id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'The id of the payment, as foresi_search_payments returns it.']],
            'required' => ['id'],
            'additionalProperties' => false,
        ], $tool->inputSchema());
    }

    public function testThePaymentIsAnsweredAsTheRestApiWritesIt(): void
    {
        $payment = $this->inTenant($this->tenant, function (): Payment {
            $invoice = $this->invoice();

            return $this->payment('2026-11-12', [$invoice->id() => '1000'], note: 'Paid by bank');
        });

        $search = $this->call(SearchPaymentsTool::class);
        self::assertSame(['items' => $this->rest('/api/v1/payments'), 'next_cursor' => null], $search->data);
        self::assertSame([$payment->id()], self::itemIds($search));
        self::assertSame('1 payment, newest first; no more match.', $search->summary);

        $get = $this->call(GetPaymentTool::class, ['id' => $payment->id()]);
        self::assertSame($this->rest('/api/v1/payments/'.$payment->id()), $get->data);
        self::assertSame('Paid by bank', $get->data['note']);
        self::assertSame('The payment with the documents it is allocated to.', $get->summary);
    }

    public function testEveryFilterNarrowsTheSearch(): void
    {
        [$first, $other, $refund, $otherFirm, $invoice, $cancelled] = $this->inTenant($this->tenant, function (): array {
            $invoice = $this->invoice();
            $first = $this->payment('2026-11-01', [$invoice->id() => '1000']);
            $refund = $this->payment('2026-11-05', [$invoice->id() => '100'], direction: PaymentDirection::Refund);
            [$firm] = $this->firmWithCustomer('Other Firm s.r.o.', '25596641');

            return [$first, $this->payment('2026-11-10', firmId: $firm, amount: '100'), $refund, $firm, $invoice, $this->payment(amount: '100', cancelled: true)];
        });

        self::assertSame([$other->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['firm_id' => $otherFirm])));
        self::assertSame([$refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['firm_id' => $this->firmId])));
        self::assertSame([$refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['invoice_id' => strtoupper($invoice->id())])));
        self::assertSame([$refund->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['direction' => 'refund'])));
        self::assertSame([$other->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['direction' => 'incoming'])));
        self::assertSame([$other->id(), $refund->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['received_from' => '2026-11-05'])));
        self::assertSame([$refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['received_to' => '2026-11-05'])));
        self::assertSame([$other->id(), $refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['include_cancelled' => false])));
        self::assertSame([$cancelled->id(), $other->id(), $refund->id(), $first->id()], self::itemIds($this->call(SearchPaymentsTool::class, ['include_cancelled' => true])));
    }

    public function testTheCursorPagesThroughTheSearch(): void
    {
        $ids = $this->inTenant($this->tenant, fn (): array => array_map(fn (int $day): string => $this->payment(\sprintf('2026-11-%02d', $day), amount: '100')->id(), [1, 2, 3]));

        self::assertSame([[$ids[2], $ids[1]], [$ids[0]]], $this->toolPages(SearchPaymentsTool::class, 2));
        self::assertSame([[$ids[2], $ids[1], $ids[0]]], $this->toolPages(SearchPaymentsTool::class, 3));
        $first = $this->call(SearchPaymentsTool::class, ['limit' => 2]);
        self::assertSame(Cursor::encode($ids[1]), $first->data['next_cursor']);
        self::assertSame('2 payments, newest first; more match: call again with the same arguments and next_cursor as cursor.', $first->summary);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidArguments(): iterable
    {
        yield 'a day that is no day' => [['received_from' => '2026-02-30'], 'The argument "received_from" cannot be used: This value is not a valid date.'];
        yield 'a last day that is no day' => [['received_to' => 'yesterday'], 'The argument "received_to" cannot be used: This value is not a valid date.'];
        yield 'a firm that is no id' => [['firm_id' => 'nope'], 'The argument "firm_id" cannot be used: This is not a valid UUID.'];
        yield 'an invoice that is no id' => [['invoice_id' => 'nope'], 'The argument "invoice_id" cannot be used: This is not a valid UUID.'];
        yield 'an unknown direction' => [['direction' => 'sideways'], 'The argument "direction" cannot be used: The value you selected is not a valid choice.'];
        yield 'a limit above the maximum' => [['limit' => 101], 'The argument "limit" cannot be used: This value should be between 1 and 100.'];
        yield 'a cursor no search gave' => [['cursor' => Cursor::encode('not-an-id')], 'The argument "cursor" is not one a search gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.'];
        yield 'a flag that is no boolean' => [['include_cancelled' => 'perhaps'], 'The argument "include_cancelled" must be true or false.'];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidArguments')]
    public function testAnArgumentThatCannotBeUsedIsRefusedByName(array $arguments, string $message): void
    {
        $this->assertRefused('invalid_argument', $message, SearchPaymentsTool::class, $arguments);
    }

    public function testAPaymentOfAnotherOrganisationIsNotFound(): void
    {
        $theirs = $this->inTenant($this->foreignTenant(), function (): Payment {
            [$firm] = $this->firmWithCustomer();

            return $this->payment(firmId: $firm, amount: '100');
        });

        $none = $this->call(SearchPaymentsTool::class);
        self::assertSame(['items' => [], 'next_cursor' => null], $none->data);
        self::assertSame('No payments match.', $none->summary);
        foreach ([$theirs->id(), Id::generate(), 'not-an-id'] as $id) {
            $this->assertRefused('not_found', 'The organisation has no payment with this id. Find the id with foresi_search_payments.', GetPaymentTool::class, ['id' => $id]);
        }
        $this->assertRefused('not_found', 'The organisation has no payment with this id. Find the id with foresi_search_payments.', GetPaymentTool::class);
    }

    public function testAGuestReadsNoPayments(): void
    {
        $payment = $this->inTenant($this->tenant, fn (): Payment => $this->payment(amount: '100'));
        $guest = $this->guest();

        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its payments.', SearchPaymentsTool::class, [], $guest);
        $this->assertRefused('forbidden', 'Only the owners and members of the organisation read its payments.', GetPaymentTool::class, ['id' => $payment->id()], $guest);
    }
}
