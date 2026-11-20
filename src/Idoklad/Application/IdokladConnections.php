<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Idoklad\Application;

use Foresi\Idoklad\Domain\IdokladConnection;

interface IdokladConnections
{
    public function get(): ?IdokladConnection;

    public function save(IdokladConnection $connection): void;

    public function remove(IdokladConnection $connection): void;
}
