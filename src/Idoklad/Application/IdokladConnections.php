<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Idoklad\Application;

use App\Idoklad\Domain\IdokladConnection;
use App\Party\Domain\LegalEntity;

interface IdokladConnections
{
    public function for(LegalEntity $firm): ?IdokladConnection;

    public function save(IdokladConnection $connection): void;

    public function remove(IdokladConnection $connection): void;
}
