<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Infrastructure\Operation;

trigger_deprecation('foresi/shared', '0.11', 'The "%s" interface is deprecated, use "%s" instead.', OperationHandler::class, \Foresi\Shared\Contract\Operation\OperationHandler::class);

class_alias(\Foresi\Shared\Contract\Operation\OperationHandler::class, OperationHandler::class);

if (false) { // @phpstan-ignore if.alwaysFalse (a declaration for IDEs and static analysis only)
    /**
     * @deprecated since module API 1.0, removed in 2.0, use \Foresi\Shared\Contract\Operation\OperationHandler instead
     */
    interface OperationHandler extends \Foresi\Shared\Contract\Operation\OperationHandler
    {
    }
}
