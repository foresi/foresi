<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Shared\Domain\Module;

/**
 * What a module is for. The core is what every application needs, the UI is
 * the screens, every other module is a business area.
 */
enum ModuleKind: string
{
    case Core = 'core';
    case Module = 'module';
    case Ui = 'ui';
}
