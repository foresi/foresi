<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" interface is deprecated, use "%s" instead.', ReadTool::class, \Foresi\Shared\Contract\Mcp\ReadTool::class);

class_alias(\Foresi\Shared\Contract\Mcp\ReadTool::class, ReadTool::class);

if (false) { // @phpstan-ignore if.alwaysFalse (a declaration for IDEs and static analysis only)
    /**
     * @deprecated since module API 1.0, removed in 3.0, use \Foresi\Shared\Contract\Mcp\ReadTool instead
     */
    interface ReadTool extends \Foresi\Shared\Contract\Mcp\ReadTool
    {
    }
}
