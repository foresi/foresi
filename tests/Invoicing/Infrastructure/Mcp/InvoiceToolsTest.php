<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Invoicing\Infrastructure\Mcp;

use Foresi\Invoicing\Infrastructure\Mcp\GetInvoiceTool;
use Foresi\Invoicing\Infrastructure\Mcp\SearchInvoicesTool;
use Foresi\Tests\Invoicing\Support\IssuesInvoices;
use Foresi\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * foresi_search_invoices and foresi_get_invoice answer what GET /api/v1/invoices
 * and /api/v1/invoices/{id} answer, narrowed by the same filters.
 */
final class InvoiceToolsTest extends ReadToolTestCase
{
    use IssuesInvoices;

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
        self::assertSame(['kind', 'customer_id', 'state', 'trashed', 'issued_from', 'issued_to', 'query', 'view_id', 'filter', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame(['invoice', 'advance', 'payment_tax_document', 'correction_document'], self::argument($tool, 'kind')['enum']);
        self::assertSame(['unpaid', 'overdue', 'paid'], self::argument($tool, 'state')['enum']);
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
}
