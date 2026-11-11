<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiScope;

/**
 * The personal API token the current request or process signed in with; none
 * for a sign-in on the screens.
 */
interface ApiTokenContext
{
    public function grant(): ?ApiTokenGrant;

    /**
     * For work reached only through a token: without one nothing is allowed.
     *
     * @throws InsufficientScope
     */
    public function requireScope(ApiScope $scope): ApiTokenGrant;
}
