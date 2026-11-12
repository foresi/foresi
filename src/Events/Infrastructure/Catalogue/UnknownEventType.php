<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Events\Infrastructure\Catalogue;

/**
 * Asked for an event type the catalogue does not hold: no module publishes it,
 * or the class does not follow the rules of a contract event.
 */
final class UnknownEventType extends \RuntimeException
{
    public static function named(string $name, int $version): self
    {
        return new self(\sprintf('Unknown event type %s@%d.', $name, $version));
    }

    public static function ofClass(string $class): self
    {
        return new self(\sprintf('%s is not a contract event of an installed module.', $class));
    }
}
