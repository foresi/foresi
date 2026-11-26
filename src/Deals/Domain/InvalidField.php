<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Deals\Domain;

/**
 * A value the domain refuses, with the reason and the name of the field it
 * came in, as the use cases name it ("title", "quantity").
 */
final class InvalidField extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
