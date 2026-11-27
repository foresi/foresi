<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Integration\Ui\Column;

use Foresi\Identity\Application\Person;
use Foresi\Identity\Domain\ApiToken;

/**
 * One row of a table of Settings → API: a token or a connected app, the person it belongs to
 * where the table shows the other people's, and for a connected app the host that vouches
 * for its name, null while none does.
 */
final readonly class ApiTokenRow
{
    public function __construct(public ApiToken $token, public ?Person $person = null, public ?string $host = null)
    {
    }
}
