<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Application;

/**
 * Why the task of a date of a recurring task could not be created: the code is
 * recorded on the recurring task, which shows it until a run creates its task.
 */
final class OccurrenceRefused extends \DomainException
{
    public const string ARCHIVED = 'archived';
    public const string CUSTOM_FIELDS = 'custom_fields';
    public const string NO_STATUS = 'no_status';

    public function __construct(public readonly string $reason)
    {
        parent::__construct(\sprintf('The task of the recurring task was not created: %s.', $reason));
    }
}
