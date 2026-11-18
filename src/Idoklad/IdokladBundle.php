<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad;

use Foresi\Idoklad\Application\Message\ProcessIdokladJob;
use Foresi\Shared\Infrastructure\Module\ModuleBundle;

/**
 * The move from iDoklad: connections, pulls and the import of what they hold.
 */
final class IdokladBundle extends ModuleBundle
{
    protected function messageRouting(): array
    {
        return [
            // The pulls, checks and runs of the move from iDoklad.
            ProcessIdokladJob::class => 'async',
        ];
    }
}
