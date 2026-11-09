<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * Who of the team sees a project: the whole organisation, or only the people
 * it is shared with. Guests see a project only when it is shared with them,
 * whichever it is; owners see every project.
 */
enum ProjectVisibility: string
{
    case Organisation = 'organisation';
    case Chosen = 'chosen';
}
