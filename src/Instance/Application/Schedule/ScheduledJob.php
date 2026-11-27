<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Application\Schedule;

trigger_deprecation('foresi/instance', '0.11', 'The "%s" interface is deprecated, use "%s" instead.', ScheduledJob::class, \Foresi\Instance\Contract\ScheduledJob::class);

class_alias(\Foresi\Instance\Contract\ScheduledJob::class, ScheduledJob::class);

if (false) { // @phpstan-ignore if.alwaysFalse (a declaration for IDEs and static analysis only)
    /**
     * @deprecated since module API 1.0, removed in 3.0, use \Foresi\Instance\Contract\ScheduledJob instead
     */
    interface ScheduledJob extends \Foresi\Instance\Contract\ScheduledJob
    {
    }
}
