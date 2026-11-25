<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" class is deprecated, use "%s" instead.', 'Foresi\\Shared\\Infrastructure\\Operation\\OperationOutput', \Foresi\Shared\Contract\Operation\OperationOutput::class);

class_alias(\Foresi\Shared\Contract\Operation\OperationOutput::class, 'Foresi\\Shared\\Infrastructure\\Operation\\OperationOutput');

// A final class cannot be extended, so the old name has no declaration of its own for IDEs and static analysis.
