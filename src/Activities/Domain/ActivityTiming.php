<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Activities\Domain;

/**
 * Which time an activity of a type has: a start time and a duration (a
 * meeting, a call), a date alone (a task), or none (a note, which is done
 * when it is written).
 */
enum ActivityTiming: string
{
    case Timed = 'timed';
    case Date = 'date';
    case None = 'none';
}
