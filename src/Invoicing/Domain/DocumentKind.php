<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * The kind of a sales document; the seam for later kinds.
 */
enum DocumentKind: string
{
    case Invoice = 'invoice';
}
