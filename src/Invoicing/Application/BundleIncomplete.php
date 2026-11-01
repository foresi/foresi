<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * Documents of a period that could not be drawn, so no archive was made.
 */
final class BundleIncomplete extends \RuntimeException
{
    /**
     * @param list<string> $numbers
     */
    public function __construct(public readonly array $numbers)
    {
        parent::__construct('Documents of the period cannot be drawn: '.implode(', ', $numbers));
    }
}
