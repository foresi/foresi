<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A save the rules do not allow. Nothing was stored and no number was consumed.
 */
final class IssueRefused extends \DomainException
{
    /**
     * @param list<IssueProblem> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The document cannot be saved: '.implode(', ', array_map(static fn (IssueProblem $problem): string => $problem->key, $problems)));
    }
}
