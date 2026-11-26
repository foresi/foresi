<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Contract\Mcp;

/**
 * A read or write tool that may say it is for the organisation's own people
 * only (ADR 0041): a guest's token neither sees it in tools/list nor calls it,
 * whatever its scopes. For tools whose scope a guest may hold, or that every
 * token may call, but whose answer or change is never a guest's.
 */
interface StaffOnlyTool
{
    public function staffOnly(): bool;
}
