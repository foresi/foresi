<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Party\Domain;

/**
 * The language a party's documents are issued in.
 */
enum DocumentLanguage: string
{
    case Czech = 'cs';
    case English = 'en';
}
