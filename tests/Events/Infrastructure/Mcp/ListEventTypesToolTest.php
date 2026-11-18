<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Events\Infrastructure\Mcp;

use Foresi\Events\Infrastructure\Catalogue\EventCatalogue;
use Foresi\Events\Infrastructure\Catalogue\EventType;
use Foresi\Events\Infrastructure\Mcp\ListEventTypesTool;
use Foresi\Tests\Shared\Infrastructure\Mcp\ReadToolTestCase;

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
        // The owner reads every module that has events: the catalogue of what is installed.
        $names = array_map(static fn (EventType $type): string => $type->name, self::getContainer()->get(EventCatalogue::class)->all());
        self::assertContains('party.contact.created', $names);
        self::assertSame(\sprintf('%d event types.', \count($names)), $output->summary);
        self::assertSame($names, array_column($items, 'name'));
        $created = array_search('party.contact.created', $names, true);
        self::assertSame(['name' => 'party.contact.created', 'version' => 1, 'module' => 'party', 'subject_type' => 'contact', 'fields' => ['contact_id', 'kind']], $items[$created]);
    }

    public function testAGuestSeesNoTypes(): void
    {
        $output = $this->call(ListEventTypesTool::class, [], $this->guest());

        self::assertSame(['items' => []], $output->data);
        self::assertSame('0 event types.', $output->summary);
    }
}
