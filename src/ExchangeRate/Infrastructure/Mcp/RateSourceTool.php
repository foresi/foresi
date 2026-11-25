<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Mcp;

use Foresi\ExchangeRate\Application\ExchangeRateRefused;
use Foresi\ExchangeRate\Application\RateSourceReads;
use Foresi\ExchangeRate\Infrastructure\Api\ExchangeRateApiController;
use Foresi\ExchangeRate\Infrastructure\Api\ExchangeRateJson;
use Foresi\Shared\Contract\Mcp\ReadTool;
use Foresi\Shared\Contract\Mcp\ToolOutput;
use Foresi\Shared\Contract\Mcp\ToolRefusal;

/**
 * The source of the firm's exchange rates, as GET /api/v1/exchange-rate/source writes it.
 */
final readonly class RateSourceTool implements ReadTool
{
    public function __construct(private RateSourceReads $reads)
    {
    }

    public function name(): string
    {
        return 'foresi_get_exchange_rate_source';
    }

    public function title(): string
    {
        return 'Exchange-rate source';
    }

    public function scope(): string
    {
        return ExchangeRateApiController::READ_SCOPE;
    }

    public function description(): string
    {
        return 'Returns where the firm\'s exchange rates to CZK come from: source is the name in use, such as "cnb" (the Czech National Bank) or "ecb" (the European Central Bank reference rates, converted to CZK through the euro), null when none is installed; chosen is true when a person chose it and false while the default applies; default is the default of the firm\'s country; allowed lists the sources the firm may choose with foresi_set_exchange_rate_source, installed every installed one, and country the country whose rules decide, null without rules. '
            .'A received document takes the rate of its tax point from this source and keeps it.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass(), 'additionalProperties' => false];
    }

    public function call(array $arguments): ToolOutput
    {
        try {
            $view = $this->reads->current();
        } catch (ExchangeRateRefused $refused) {
            throw ToolRefusal::of($refused);
        }

        return new ToolOutput(ExchangeRateJson::source($view), \sprintf('The exchange rates come from %s%s.', $view->source ?? 'no source', $view->chosen ? ', as chosen' : ', the default'));
    }
}
