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
 * One deal, as GET /api/v1/deals/{dealId} writes it.
 */
final readonly class GetDealTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DealReads $reads, private SalesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_get_deal';
    }

    public function title(): string
    {
        return 'Get a deal';
    }

    public function scope(): string
    {
        return 'deals:read';
    }

    public function description(): string
    {
        return 'Returns one deal with its client, owner, stage, status and the lost reason and note of a lost one; its contacts (the client\'s contact persons it is talked through with); its lines (name, unit, quantity, unit_price, billing_kind and the period of a recurring one; for a time_based line linked to a project, the project and the billable minutes logged on it, whose time is invoiced at the line\'s rate); '
            .'its value: one_off and monthly totals, one per currency and never converted, approximate when a weekly or daily line was put on a month\'s terms, and hourly, the rate of each time_based line with its expected hours and expected amount when hours are expected, never added into the other two; and its links: a follows_from link is outgoing when this deal follows from the other deal. Its custom_fields hold the custom fields by key (see foresi_list_fields). A deal in the trash is returned too. Find ids with foresi_search_deals.';
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
            'properties' => ['id' => ToolSchema::idSchema('The id of the deal, as foresi_search_deals returns it.')],
            'required' => ['id'],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $detail = $this->reads->deal(ToolSchema::text($arguments, 'id') ?? '');
        } catch (SalesRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput($this->json->deal($detail), \sprintf('The deal %s.', $detail->deal->title()));
    }
}
