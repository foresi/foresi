<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi;

use Foresi\Shared\Infrastructure\Kernel\ForesiKernel;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * The development application: every module, through the path repositories of src/*.
 */
class Kernel extends BaseKernel
{
    use ForesiKernel;
}
