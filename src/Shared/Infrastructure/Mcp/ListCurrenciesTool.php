<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

use Foresi\Shared\Application\Api\ScopeGuard;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;
use Foresi\Shared\Infrastructure\Currency\CurrencyList;

/**
 * The ISO 4217 currencies an amount may be in, the same answer as GET /api/v1/currencies.
 */
final readonly class ListCurrenciesTool implements ReadTool
{
    public function name(): string
    {
        return 'foresi_list_currencies';
    }

    public function title(): string
    {
        return 'List currencies';
    }

    public function scope(): string
    {
        return ScopeGuard::ANY;
    }

    public function description(): string
    {
        return 'Returns the ISO 4217 currencies an amount may be in, ordered by code: code, English name, minor_units (the decimals of the currency: 2 for EUR, 0 for JPY, 3 for BHD; amount_minor divided by 10 to this power is the amount), numeric code and historic. Pass historic: true to list withdrawn codes too, such as HRK, which older documents may be in.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['historic' => ['type' => 'boolean', 'description' => 'true to list the withdrawn codes too; false when left out.']],
            'additionalProperties' => false,
        ];
    }

    public function call(array $arguments): ToolOutput
    {
        $historic = $arguments['historic'] ?? false;
        if (!\is_bool($historic)) {
            throw ToolRefusal::invalidArgument('The argument "historic" must be true or false.');
        }
        $rows = CurrencyList::rows($historic);

        return new ToolOutput(['currencies' => $rows], \sprintf('%d currencies.', \count($rows)));
    }
}
