<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Mcp;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" class is deprecated, use "%s" instead.', 'Foresi\\Shared\\Infrastructure\\Mcp\\ToolOutput', \Foresi\Shared\Contract\Mcp\ToolOutput::class);

class_alias(\Foresi\Shared\Contract\Mcp\ToolOutput::class, 'Foresi\\Shared\\Infrastructure\\Mcp\\ToolOutput');

// A final class cannot be extended, so the old name has no declaration of its own for IDEs and static analysis.
