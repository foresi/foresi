<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Domain\View;

/**
 * Who sees a view: its owner only, or every staff member of the instance (spec D6).
 */
enum Visibility: string
{
    case Private = 'private';
    case Shared = 'shared';
}
