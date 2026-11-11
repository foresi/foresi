<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Domain;

/**
 * What a personal API token may do: read data, or change it too.
 */
enum ApiScope: string
{
    case Read = 'read';
    case Write = 'write';
}
