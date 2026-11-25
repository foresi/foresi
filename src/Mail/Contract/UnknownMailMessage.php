<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Mail\Contract;

/**
 * No stored message, or no such part of one, has this id.
 */
final class UnknownMailMessage extends \RuntimeException
{
    public function __construct(string $message = 'No stored mail message has this id.')
    {
        parent::__construct($message);
    }
}
