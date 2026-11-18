<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Infrastructure\Identity;

use Foresi\Identity\Contract\GuestHome;
use Foresi\Work\Infrastructure\Controller\SharedWorkController;

/**
 * A guest starts on what the organisation shares with them.
 */
final readonly class SharedWorkGuestHome implements GuestHome
{
    public function route(): string
    {
        return SharedWorkController::ROUTE;
    }
}
