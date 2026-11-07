<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

use App\Work\Domain\StatusType;

final readonly class StatusChoice
{
    public function __construct(public string $id, public string $name, public StatusType $type)
    {
    }
}
