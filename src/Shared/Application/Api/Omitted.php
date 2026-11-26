<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" class is deprecated, use "%s" instead.', 'Foresi\\Shared\\Application\\Api\\Omitted', \Foresi\Shared\Contract\Operation\Omitted::class);

class_alias(\Foresi\Shared\Contract\Operation\Omitted::class, 'Foresi\\Shared\\Application\\Api\\Omitted');

// A final class cannot be extended, so the old name has no declaration of its own for IDEs and static analysis.
