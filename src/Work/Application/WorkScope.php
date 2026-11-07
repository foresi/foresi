<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Application;

/**
 * What the queries may show the signed-in person, decided by WorkVisibility and
 * applied by each query in its own language. Today a person sees everything or
 * nothing; shared projects, sections and tasks will add the ids a person was
 * given, and a query that does not know a scope yet shows nothing.
 */
final readonly class WorkScope
{
    private function __construct(private bool $everything)
    {
    }

    public static function everything(): self
    {
        return new self(true);
    }

    public static function nothing(): self
    {
        return new self(false);
    }

    public function isEverything(): bool
    {
        return $this->everything;
    }
}
