<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Invoicing\Domain;

/**
 * Where a recurring template is in its life. A paused template can be resumed;
 * an ended one stays ended, whether its end rule or a person ended it.
 */
enum RecurringState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Ended = 'ended';
}
