<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Work\Domain;

/**
 * The one rule for the names and titles of the work module: trimmed, never
 * empty, and short enough for their column, so a form and an import fail alike.
 */
final readonly class Name
{
    /**
     * @throws \DomainException for an empty or too long name
     */
    public static function of(string $value, int $max, string $what): string
    {
        $value = trim($value);
        if ('' === $value) {
            throw new \DomainException(sprintf('%s needs a name.', $what));
        }
        if (mb_strlen($value) > $max) {
            throw new \DomainException(sprintf('%s name is at most %d characters long.', $what, $max));
        }

        return $value;
    }
}
