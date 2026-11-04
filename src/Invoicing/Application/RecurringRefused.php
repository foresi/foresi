<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Application;

/**
 * A change of a recurring template the rules do not allow. Nothing was stored.
 */
final class RecurringRefused extends \DomainException
{
    /**
     * @param list<IssueProblem> $problems
     */
    public function __construct(public readonly array $problems)
    {
        parent::__construct('The recurring invoice cannot be changed: '.implode(', ', array_map(static fn (IssueProblem $problem): string => $problem->key, $problems)));
    }

    public static function because(string $field, string $key): self
    {
        return new self([new IssueProblem($field, $key)]);
    }
}
