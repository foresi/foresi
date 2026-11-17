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
        self::assertSame('18 event types.', $output->summary);
        self::assertSame(['banking.movement.recorded', 'cash-flow.planned_item.changed', 'cash-flow.planned_item.created', 'cash-flow.planned_item.deleted', 'cash-flow.planned_item.ended', 'cash-flow.planned_item.occurrence_skipped', 'invoicing.invoice.delivered', 'invoicing.invoice.issued', 'invoicing.invoice.paid', 'invoicing.invoice.restored', 'invoicing.invoice.trashed', 'invoicing.payment.recorded', 'party.contact.archived', 'party.contact.created', 'party.contact.restored', 'party.contact.updated', 'purchasing.received_document.paid', 'purchasing.received_document.recorded'], array_column($items, 'name'));
        self::assertSame(['name' => 'invoicing.invoice.paid', 'version' => 1, 'module' => 'invoicing', 'subject_type' => 'invoice', 'fields' => ['invoice_id', 'state', 'payment_id']], $items[8]);
        self::assertSame(['payment', 'invoice', 'contact'], [array_column($items, 'subject_type')[11], array_column($items, 'subject_type')[6], array_column($items, 'subject_type')[13]]);
        self::assertSame(['name' => 'party.contact.created', 'version' => 1, 'module' => 'party', 'subject_type' => 'contact', 'fields' => ['contact_id', 'kind']], $items[13]);
    }

    public function testAGuestSeesNoTypes(): void
    {
        $output = $this->call(ListEventTypesTool::class, [], $this->guest());

        self::assertSame(['items' => []], $output->data);
        self::assertSame('0 event types.', $output->summary);
    }
}
