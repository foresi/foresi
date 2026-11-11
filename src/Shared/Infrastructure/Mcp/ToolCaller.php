<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Mcp;

/**
 * Makes sure, before every tool call, that whoever signed the MCP client in
 * may still act. Over HTTP the firewall has just checked the token of the
 * request; the long-running stdio process checks its token again, as it may
 * have expired or been revoked since the last call.
 */
interface ToolCaller
{
    /**
     * @throws ToolRefusal when the caller may no longer act
     */
    public function confirm(): void;
}
