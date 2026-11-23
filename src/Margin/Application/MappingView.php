<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Margin\Application;

/**
 * A mapping of a line as it was typed: its kind and its recipients in order.
 */
final readonly class MappingView
{
    /**
     * @param list<TermView> $terms
     */
    public function __construct(public string $kind, public array $terms)
    {
    }
}
