<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

use Foresi\Work\Domain\LabelColour;

final readonly class LabelBadge
{
    public function __construct(public string $id, public string $name, public LabelColour $colour)
    {
    }
}
