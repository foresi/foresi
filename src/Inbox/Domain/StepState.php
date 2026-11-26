<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Inbox\Domain;

/**
 * Where one step of a suggestion stands: pending until it runs, done once its
 * record is made, failed when its action refused it (it may run again), or
 * skipped when the person unticked it. Done and skipped are final.
 */
enum StepState: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
