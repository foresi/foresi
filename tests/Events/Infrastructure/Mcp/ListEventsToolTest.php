<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Mcp;

use App\Events\Infrastructure\Api\PositionCursor;
use App\Events\Infrastructure\Mcp\ListEventsTool;
use App\Events\Infrastructure\Relay\EventRelay;
use App\Shared\Application\Event\EventRecorder;
use App\Shared\Domain\Id;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Tenancy\Application\TenantContext;
use App\Tests\Events\Support\RecordedEvents;
use App\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;
use Doctrine\DBAL\Connection;

/**
 * foresi_list_events answers what GET /api/v1/events answers, narrowed by the
 * same types and read on by the same cursor.
 */
final class ListEventsToolTest extends ReadToolTestCase
{
    private RecordedEvents $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->events = new RecordedEvents(
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(Connection::class),
            self::getContainer()->get(EventRecorder::class),
            self::getContainer()->get(EventRelay::class),
        );
    }

    public function testTheToolDescribesItsArguments(): void
    {
        $tool = $this->tool(ListEventsTool::class);

        self::assertSame('foresi_list_events', $tool->name());
        self::assertSame('List events', $tool->title());
        self::assertStringStartsWith('Lists the domain events of the organisation from the last 30 days', $tool->description());
        self::assertStringContainsString('call again with next_cursor as cursor', $tool->description());
        self::assertSame(['types', 'limit', 'cursor'], self::argumentNames($tool));
        self::assertSame('array', self::argument($tool, 'types')['type']);
        self::assertSame(['type' => 'string'], self::argument($tool, 'types')['items']);
        self::assertSame(100, self::argument($tool, 'limit')['maximum']);
        self::assertFalse($tool->inputSchema()['additionalProperties']);
    }

    public function testItAnswersAsTheRestApiDoes(): void
    {
        $invoice = Id::generate();
        $this->events->record($this->tenant, RecordedEvents::issued($invoice));
        $this->events->record($this->tenant, RecordedEvents::payment(Id::generate(), $invoice));
        $this->events->record($this->tenant, RecordedEvents::paid($invoice));
        $this->events->number();

        $all = $this->call(ListEventsTool::class);
        $this->get('/api/v1/events');
        self::assertSame($this->json()['data'], $all->data['items']);
        self::assertSame($this->json()['next_cursor'], $all->data['next_cursor']);
        self::assertSame('3 events, oldest first; call again with the same arguments and next_cursor as cursor to read on.', $all->summary);

        $narrow = $this->call(ListEventsTool::class, ['types' => ['invoicing.payment.recorded', 'invoicing.invoice.paid'], 'limit' => 5]);
        $this->get('/api/v1/events', ['types' => 'invoicing.payment.recorded,invoicing.invoice.paid', 'limit' => '5']);
        self::assertSame($this->json()['data'], $narrow->data['items']);
        self::assertSame(['invoicing.payment.recorded', 'invoicing.invoice.paid'], $this->column($narrow, 'type'));
    }

    public function testTheCursorContinuesAndStaysWhenNothingIsNew(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        }
        $this->events->number();

        $first = $this->call(ListEventsTool::class, ['limit' => 2]);
        self::assertSame([1, 2], $this->column($first, 'position'));
        self::assertSame(PositionCursor::encode(2), $first->data['next_cursor']);

        $second = $this->call(ListEventsTool::class, ['limit' => 2, 'cursor' => $first->data['next_cursor']]);
        self::assertSame([3], $this->column($second, 'position'));

        $none = $this->call(ListEventsTool::class, ['cursor' => $second->data['next_cursor']]);
        self::assertSame([], $none->data['items']);
        self::assertSame($second->data['next_cursor'], $none->data['next_cursor']);
        self::assertSame('No new events; call again later with the same arguments and cursor.', $none->summary);
    }

    public function testBadArgumentsAreRefused(): void
    {
        $this->assertRefused('invalid_argument', 'The argument "types" must be a list of strings.', ListEventsTool::class, ['types' => 'invoicing.*']);
        $this->assertRefused('invalid_argument', 'The argument "types" must be a list of strings.', ListEventsTool::class, ['types' => [1]]);
        $this->assertRefused('invalid_argument', 'The argument "types" cannot be used: The event type "invoicing.invoice.nothing" does not exist. Use an event name or "module.*".', ListEventsTool::class, ['types' => ['invoicing.invoice.nothing']]);
        $this->assertRefused('invalid_argument', 'The argument "cursor" is not one this tool gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.', ListEventsTool::class, ['cursor' => Id::generate()]);
        $this->assertRefused('invalid_argument', 'The argument "limit" cannot be used: This value should be between 1 and 100.', ListEventsTool::class, ['limit' => 101]);
        $this->assertRefused('invalid_argument', 'The argument "limit" must be an integer.', ListEventsTool::class, ['limit' => 'ten']);
    }

    public function testNonStaffSees403ForInvoicingTypesAndAnEmptyListWithoutTypes(): void
    {
        $this->events->record($this->tenant, RecordedEvents::issued(Id::generate()));
        $this->events->number();
        $guest = $this->guest();

        $this->assertRefused('forbidden', 'The events of "invoicing" are not yours to read.', ListEventsTool::class, ['types' => ['invoicing.*']], $guest);
        $none = $this->call(ListEventsTool::class, [], $guest);
        self::assertSame(['items' => [], 'next_cursor' => null], $none->data);
    }

    public function testOtherTenantsEventsAreInvisible(): void
    {
        $this->events->record($this->foreignTenant(), RecordedEvents::issued(Id::generate()));
        $this->events->number();

        self::assertSame([], $this->call(ListEventsTool::class)->data['items']);
    }

    /**
     * @return list<mixed>
     */
    private function column(ToolOutput $output, string $key): array
    {
        $items = $output->data['items'];
        self::assertIsArray($items);

        return array_column($items, $key);
    }
}
