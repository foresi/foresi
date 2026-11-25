<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\ExchangeRate\Infrastructure\Api;

use Foresi\ExchangeRate\Application\RateSourceView;

/**
 * The exchange-rate source as the REST API and MCP write it.
 */
final class ExchangeRateJson
{
    /**
     * @return array{source: ?string, chosen: bool, default: ?string, allowed: list<string>, installed: list<string>, country: ?string}
     */
    public static function source(RateSourceView $view): array
    {
        return [
            'source' => $view->source,
            'chosen' => $view->chosen,
            'default' => $view->default,
            'allowed' => $view->allowed,
            'installed' => $view->installed,
            'country' => $view->country,
        ];
    }
}
