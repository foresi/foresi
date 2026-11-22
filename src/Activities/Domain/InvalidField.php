<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

/**
 * A value the domain refuses, with the name of the field it came in, as the
 * use cases and the screens name it ("plannedAt", "subject").
 */
final class InvalidField extends \InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
