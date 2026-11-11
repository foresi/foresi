<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

/**
 * The stateless firewalls a personal API token signs in on.
 */
final class ApiFirewalls
{
    public const string API = 'api';
    public const string MCP = 'mcp';
    public const array NAMES = [self::API, self::MCP];
}
