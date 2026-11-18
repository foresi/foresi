<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Import\Domain;

enum DecimalSeparator: string
{
    case Comma = 'comma';
    case Point = 'point';
}
