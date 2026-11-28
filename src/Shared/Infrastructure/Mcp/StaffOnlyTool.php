<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" interface is deprecated, use "%s" instead.', StaffOnlyTool::class, \Foresi\Shared\Contract\Mcp\StaffOnlyTool::class);

class_alias(\Foresi\Shared\Contract\Mcp\StaffOnlyTool::class, StaffOnlyTool::class);

if (false) { // @phpstan-ignore if.alwaysFalse (a declaration for IDEs and static analysis only)
    /**
     * @deprecated since module API 1.0, removed in 4.0, use \Foresi\Shared\Contract\Mcp\StaffOnlyTool instead
     */
    interface StaffOnlyTool extends \Foresi\Shared\Contract\Mcp\StaffOnlyTool
    {
    }
}
