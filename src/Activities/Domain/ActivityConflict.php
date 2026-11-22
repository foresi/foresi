<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

/**
 * A change the activity's state does not allow, such as completing one that
 * is done already, with a reason the use cases report.
 */
final class ActivityConflict extends \DomainException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
