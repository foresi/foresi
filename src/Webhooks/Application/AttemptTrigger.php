<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Webhooks\Application;

/**
 * What asked for an attempt: the schedule of the delivery (the first attempt and
 * the retries), a person redelivering it by hand, or a ping of the endpoint.
 */
enum AttemptTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Ping = 'ping';
}
