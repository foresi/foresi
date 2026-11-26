<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Infrastructure\Mcp;

use Foresi\Deals\Application\DealReads;
use Foresi\Deals\Application\SalesRefused;
use Foresi\Deals\Infrastructure\Api\SalesJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\StaffOnlyTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Contract\Mcp\ToolSchema;

/**
 * The deals found by text and narrowed by status, stage, owner and client, as
 * GET /api/v1/sales/deals writes them.
 */
final readonly class SearchDealsTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DealReads $reads, private SalesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_search_deals';
    }

    public function title(): string
    {
        return 'Search deals';
    }

    public function scope(): string
    {
        return 'sales:read';
    }

    public function description(): string
    {
        return 'Returns the deals not in the trash whose title holds the text, all of them without one, the newest first, narrowed by each filter given: {items} with the id, title, status (open, won or lost), client {id, name}, owner_user_id, stage {id, key, name}, won_at, lost_at, lost_reason and its custom_fields by key (see foresi_list_fields). '
            .'The deals of a client: give client_party_id (from foresi_search_contacts). Stage ids come from foresi_list_deal_stages. Read the lines, value, links and contact persons of one with foresi_get_deal.';
    }

    /**
     * The firm's deals, never a guest's (ADR 0041).
     */
    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'maxLength' => DealReads::MAX_TEXT, 'description' => 'The text the title holds, without regard to case; left out for every deal.'],
                'status' => ToolSchema::choiceSchema(['open', 'won', 'lost'], 'Only the deals of this status.'),
                'stage_id' => ToolSchema::idSchema('Only the deals at this stage.'),
                'owner_user_id' => ToolSchema::idSchema('Only the deals this owner or member works on.'),
                'client_party_id' => ToolSchema::idSchema('Only the deals of this client.'),
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $deals = $this->reads->deals(
                ToolSchema::text($arguments, 'status'),
                ToolSchema::text($arguments, 'stage_id'),
                ToolSchema::text($arguments, 'owner_user_id'),
                ToolSchema::text($arguments, 'client_party_id'),
                ToolSchema::text($arguments, 'query'),
            );
        } catch (SalesRefused $refused) {
            throw ToolRefusal::of($refused);
        }
        $count = \count($deals);

        return new ToolOutput(
            ['items' => $this->json->summaries($deals)],
            1 === $count ? '1 deal.' : \sprintf('%d deals, the newest first.', $count),
        );
    }
}
