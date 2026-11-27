<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" enum is deprecated, use "%s" instead.', RefusalKind::class, \Foresi\Shared\Contract\RefusalKind::class);

class_alias(\Foresi\Shared\Contract\RefusalKind::class, RefusalKind::class);

if (false) { // @phpstan-ignore if.alwaysFalse (a declaration for IDEs and static analysis only)
    /**
     * @deprecated since module API 1.0, removed in 3.0, use \Foresi\Shared\Contract\RefusalKind instead
     */
    enum RefusalKind: string
    {
        case Invalid = 'invalid';
        case Forbidden = 'forbidden';
        case NotFound = 'not_found';
        case Conflict = 'conflict';
        case Unavailable = 'unavailable';
    }
}
