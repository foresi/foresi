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
 * The stages of a deal and the reasons it is lost for, as
 * GET /api/v1/deal-stages and GET /api/v1/lost-reasons write them.
 */
final readonly class ListDealStagesTool implements ReadTool, StaffOnlyTool
{
    public function __construct(private DealReads $reads, private SalesJson $json)
    {
    }

    public function name(): string
    {
        return 'foresi_list_deal_stages';
    }

    public function title(): string
    {
        return 'List the deal stages and lost reasons';
    }

    public function scope(): string
    {
        return 'deals:read';
    }

    public function description(): string
    {
        return 'Returns {stages, lost_reasons}: the stages a deal moves through and the reasons a deal is lost for, each in its order with its id, key (set on the ones Foresi seeded), name and position. '
            .'Use a stage id with foresi_create_deal or foresi_change_deal_stage, a lost reason id with foresi_lose_deal. Archived ones only with include_archived.';
    }

    /**
     * The firm's sales settings, never a guest's (ADR 0041).
     */
    public function staffOnly(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['include_archived' => ['type' => 'boolean', 'description' => 'Also the archived stages and lost reasons, after the others.']],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $archived = ToolSchema::flag($arguments, 'include_archived');
        try {
            $stages = $this->reads->stages($archived);
            $reasons = $this->reads->lostReasons($archived);
        } catch (SalesRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(
            ['stages' => array_map($this->json->stage(...), $stages), 'lost_reasons' => array_map($this->json->lostReason(...), $reasons)],
            \sprintf('%d stages and %d lost reasons.', \count($stages), \count($reasons)),
        );
    }
}
