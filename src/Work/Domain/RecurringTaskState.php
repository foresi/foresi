<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Work\Domain;

/**
 * Where a recurring task is in its life, as a recurring invoice: a paused one
 * can be resumed; an ended one stays ended, whether its end rule or a person
 * ended it.
 */
enum RecurringTaskState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
