<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Event;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" class is deprecated, use "%s" instead.', 'Foresi\\Shared\\Application\\Event\\RecordedEvent', \Foresi\Shared\Contract\Event\RecordedEvent::class);

class_alias(\Foresi\Shared\Contract\Event\RecordedEvent::class, 'Foresi\\Shared\\Application\\Event\\RecordedEvent');

// A final class cannot be extended, so the old name has no declaration of its own for IDEs and static analysis.
