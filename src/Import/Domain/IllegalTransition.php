<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Import\Domain;

final class IllegalTransition extends \DomainException
{
    public static function between(ImportState $from, ImportState $to): self
    {
        return new self(\sprintf('An import cannot move from "%s" to "%s".', $from->value, $to->value));
    }
}
