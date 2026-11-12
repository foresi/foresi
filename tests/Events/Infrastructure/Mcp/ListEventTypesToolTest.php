<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Events\Infrastructure\Mcp;

use App\Events\Infrastructure\Mcp\ListEventTypesTool;
use App\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;

/**
 * foresi_list_event_types lists the contract events of the modules the caller may read.
 */
final class ListEventTypesToolTest extends ReadToolTestCase
{
    public function testItDescribesItselfWithoutArguments(): void
    {
        $tool = $this->tool(ListEventTypesTool::class);

        self::assertSame('foresi_list_event_types', $tool->name());
        self::assertSame('List event types', $tool->title());
        self::assertStringStartsWith('Lists the kinds of domain event', $tool->description());
        self::assertEquals(['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false], $tool->inputSchema());
    }

    public function testItListsTheTypesOfTheReadableModules(): void
    {
        $output = $this->call(ListEventTypesTool::class);

        $items = $output->data['items'];
        self::assertIsArray($items);
        self::assertSame('6 event types.', $output->summary);
        self::assertSame(['invoicing.invoice.delivered', 'invoicing.invoice.issued', 'invoicing.invoice.paid', 'invoicing.invoice.restored', 'invoicing.invoice.trashed', 'invoicing.payment.recorded'], array_column($items, 'name'));
        self::assertSame(['name' => 'invoicing.invoice.paid', 'version' => 1, 'module' => 'invoicing', 'subject_type' => 'invoice', 'fields' => ['invoice_id', 'state', 'payment_id']], $items[2]);
        self::assertSame(['payment', 'invoice'], [array_column($items, 'subject_type')[5], array_column($items, 'subject_type')[0]]);
    }

    public function testAGuestSeesNoTypes(): void
    {
        $output = $this->call(ListEventTypesTool::class, [], $this->guest());

        self::assertSame(['items' => []], $output->data);
        self::assertSame('0 event types.', $output->summary);
    }
}
