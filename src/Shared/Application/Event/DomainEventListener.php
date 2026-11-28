<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Event;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" interface is deprecated, use "%s" instead.', DomainEventListener::class, \Foresi\Shared\Contract\Event\DomainEventListener::class);

class_alias(\Foresi\Shared\Contract\Event\DomainEventListener::class, DomainEventListener::class);

if (false) { // @phpstan-ignore if.alwaysFalse (a declaration for IDEs and static analysis only)
    /**
     * @deprecated since module API 1.0, removed in 4.0, use \Foresi\Shared\Contract\Event\DomainEventListener instead
     */
    interface DomainEventListener extends \Foresi\Shared\Contract\Event\DomainEventListener
    {
    }
}
