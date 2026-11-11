<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Infrastructure\Mcp;

use App\Party\Application\PartyNotAllowed;
use App\Party\Application\Query\FirmList;
use App\Party\Application\Query\FirmRow;
use App\Shared\Infrastructure\Mcp\ReadTool;
use App\Shared\Infrastructure\Mcp\ToolOutput;
use App\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The firms of the organisation, the ids the other tools take as firm_id.
 */
final readonly class ListFirmsTool implements ReadTool
{
    public function __construct(private FirmList $firms)
    {
    }

    public function name(): string
    {
        return 'foresi_list_firms';
    }

    public function title(): string
    {
        return 'List firms';
    }

    public function description(): string
    {
        return 'Returns the firms of the organisation, the companies that issue its documents and receive its payments, by name: {items} with the id, name and whether the firm is archived. '
            .'An organisation has few firms, so there is no paging. '
            .'Use an id as firm_id of foresi_search_invoices or foresi_search_payments.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $firms = $this->firms->all();
        } catch (PartyNotAllowed) {
            throw ToolRefusal::forbidden('Only the owners and members of the organisation read its firms.');
        }
        $count = \count($firms);

        return new ToolOutput(
            ['items' => array_map(static fn (FirmRow $firm): array => ['id' => $firm->id, 'name' => $firm->name, 'archived' => $firm->archived], $firms)],
            1 === $count ? '1 firm.' : \sprintf('%d firms, by name.', $count),
        );
    }
}
