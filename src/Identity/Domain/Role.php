<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * What a user is within one tenant. A starting set: fine-grained permissions
 * come later.
 */
enum Role: string
{
    case Owner = 'owner';
    case Member = 'member';
    case Subcontractor = 'subcontractor';
    case Client = 'client';
}
