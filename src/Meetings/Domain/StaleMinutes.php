<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Meetings\Domain;

/**
 * The minutes were saved by someone else since they were read: nothing is merged.
 */
final class StaleMinutes extends \DomainException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct(\sprintf('The minutes are at version %d now.', $currentVersion));
    }
}
