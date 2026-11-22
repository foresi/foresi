<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Catalog\Contract;

/**
 * No product of the catalog has the id asked for.
 */
final class UnknownProduct extends \RuntimeException
{
    public static function withId(string $id): self
    {
        return new self(\sprintf('The catalog has no product with the id "%s".', $id));
    }
}
