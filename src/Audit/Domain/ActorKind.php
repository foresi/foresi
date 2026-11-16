<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Audit\Domain;

/**
 * What kind of actor made a change: a person on the screens, a personal API
 * token over REST, an agent over MCP, or the system itself when nobody is
 * signed in.
 */
enum ActorKind: string
{
    case User = 'user';
    case ApiToken = 'api_token';
    case McpAgent = 'mcp_agent';
    case System = 'system';
}
