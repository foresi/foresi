<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * One reason a document cannot be saved: the field it belongs to and the
 * translation key of what is wrong.
 */
final readonly class IssueProblem
{
    public function __construct(public string $field, public string $key)
    {
    }
}
