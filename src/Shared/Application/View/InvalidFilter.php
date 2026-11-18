<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\View;

/**
 * A filter a list cannot apply, with the path of the part that is wrong, such as
 * "conditions[1].op", so an API can answer 422 with it (spec D13).
 */
final class InvalidFilter extends \InvalidArgumentException
{
    public function __construct(public readonly string $path, string $message)
    {
        parent::__construct(\sprintf('%s: %s', $path, $message));
    }
}
