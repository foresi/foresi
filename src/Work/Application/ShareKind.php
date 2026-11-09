<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What can be shared, by the part of the address its sharing dialog has.
 */
enum ShareKind: string
{
    case Project = 'projects';
    case Section = 'sections';
    case Task = 'tasks';
}
