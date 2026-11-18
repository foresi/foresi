<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Shared\Application\Api;

/**
 * A field the client did not send. A partial update defaults its fields to
 * this, so a field left out keeps its value and a field sent as null clears it:
 * public string|Omitted|null $phone = new Omitted().
 */
final readonly class Omitted
{
    public static function is(mixed $value): bool
    {
        return $value instanceof self;
    }
}
