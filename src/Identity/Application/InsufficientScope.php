<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\ApiScope;

/**
 * The request's token does not have the scope the work needs, or the request
 * came without a token at all.
 */
final class InsufficientScope extends \DomainException
{
    public function __construct(public readonly ApiScope $scope)
    {
        parent::__construct(\sprintf('The token does not have the "%s" scope.', $scope->value));
    }
}
