<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Mcp;

use App\Events\Infrastructure\Api\EventListQuery;
use App\Events\Infrastructure\Api\ReadableEvents;
use App\Shared\Infrastructure\Api\InvalidParameter;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolArguments;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The feed of domain events, read as GET /api/v1/events reads it: the events of
 * the last thirty days that the token's person may read, oldest first.
 */
final readonly class ListEventsTool implements ReadTool
{
    public function __construct(private ReadableEvents $events, private ToolArguments $arguments)
    {
    }

    public function name(): string
    {
        return 'foresi_list_events';
    }

    public function title(): string
    {
        return 'List events';
    }

    public function description(): string
    {
        return 'Lists the domain events of the organisation from the last 30 days, such as an invoice being issued, delivered or paid, oldest first. '
            .'Returns {items, next_cursor}: each event has its id, type, version, position, occurred_at, the subject it is about (type, id and the API path to read it), its data (ids, codes and amounts, no personal data), a correlation_id and the kind of actor. '
            .'To follow the feed, call again with next_cursor as cursor: it continues after the last event you got. A page with fewer events than limit means you are caught up; call again later with the same cursor to see what is new. '
            .'types narrows the feed to event names such as invoicing.invoice.paid or to every event of a module such as invoicing.*; without it you get every event you may read. Use foresi_list_event_types for the names.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Event names or "module.*" for every event of a module, such as ["invoicing.invoice.paid", "invoicing.invoice.trashed"]. Leave it out for every event you may read.'],
            ] + ToolArguments::pagingSchema(),
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $asked = $arguments['types'] ?? [];
        if (!\is_array($asked) || !array_is_list($asked)) {
            throw ToolRefusal::invalidArgument('The argument "types" must be a list of strings.');
        }
        $types = [];
        foreach ($asked as $type) {
            $types[] = \is_string($type) ? $type : throw ToolRefusal::invalidArgument('The argument "types" must be a list of strings.');
        }
        $query = new EventListQuery(
            [] === $types ? null : implode(',', $types),
            ToolArguments::limit($arguments),
            ToolArguments::text($arguments, 'cursor'),
        );
        $this->arguments->validate($query);
        try {
            $page = $this->events->page($query->patterns(), $query->limit, $query->cursor);
        } catch (InvalidParameter $invalid) {
            $reason = \sprintf('The argument "%s" cannot be used: %s', $invalid->parameter, $invalid->getMessage());
            if ('cursor' === $invalid->parameter) {
                $reason = 'The argument "cursor" is not one this tool gave. Pass next_cursor of the previous answer unchanged, or leave cursor out for the first page.';
            }

            throw ToolRefusal::invalidArgument($reason);
        } catch (AccessDeniedHttpException $denied) {
            throw ToolRefusal::forbidden($denied->getMessage());
        }

        $count = \count($page['items']);
        $summary = 0 === $count
            ? 'No new events; call again later with the same arguments and cursor.'
            : \sprintf('%d %s, oldest first; call again with the same arguments and next_cursor as cursor to read on.', $count, 1 === $count ? 'event' : 'events');

        return new ToolOutput($page, $summary);
    }
}
