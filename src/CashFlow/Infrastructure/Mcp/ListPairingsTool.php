<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\CashFlow\Infrastructure\Mcp;

use Foresi\CashFlow\Application\CashFlowRefused;
use Foresi\CashFlow\Application\PairingReads;
use Foresi\CashFlow\Contract\PairingState;
use Foresi\CashFlow\Infrastructure\Api\PairingAnswers;
use Foresi\Shared\Infrastructure\Mcp\ReadTool;
use Foresi\Shared\Infrastructure\Mcp\ToolOutput;
use Foresi\Shared\Infrastructure\Mcp\ToolRefusal;

/**
 * The pairings of documents with planned occurrences, as GET /api/v1/cash-flow/pairings writes them.
 */
final readonly class ListPairingsTool implements ReadTool
{
    public function __construct(private PairingReads $pairings, private PairingAnswers $answers)
    {
    }

    public function name(): string
    {
        return 'foresi_list_pairings';
    }

    public function title(): string
    {
        return 'List document pairings';
    }

    public function scope(): string
    {
        return 'cashflow:read';
    }

    public function description(): string
    {
        return 'Returns the pairings of received documents with planned occurrences, oldest first: {items}, each with the document (subject type, id, url), its state, the planned item and the day of the occurrence it is paired with, the candidates it was proposed with and when a person decided it. '
            .'The state is auto (paired by Cash flow, the forecast no longer counts the occurrence), confirmed (a person paired it), proposed (a person should decide among the candidates) or declined (a person said it belongs to none, so Cash flow never pairs it again). Filter by state or planned_item_id. '
            .'Decide a proposal with foresi_pair_document, or with foresi_unpair_document.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string', 'enum' => array_map(static fn (PairingState $state): string => $state->value, PairingState::cases()), 'description' => 'Only the pairings in this state.'],
                'planned_item_id' => ['type' => 'string', 'format' => 'uuid', 'description' => 'Only the pairings paired or proposed with this planned item.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $state = $arguments['state'] ?? null;
        $item = $arguments['planned_item_id'] ?? null;
        if ((null !== $state && !\is_string($state)) || (null !== $item && !\is_string($item))) {
            throw new ToolRefusal(ToolRefusal::INVALID_ARGUMENT, 'state and planned_item_id are text.');
        }
        try {
            $rows = $this->answers->rows($this->pairings->list($state, $item));
        } catch (CashFlowRefused $refused) {
            throw CashFlowToolRefusal::of($refused);
        }
        $count = \count($rows);

        return new ToolOutput(['items' => $rows], 1 === $count ? '1 pairing.' : \sprintf('%d pairings, oldest first.', $count));
    }
}
