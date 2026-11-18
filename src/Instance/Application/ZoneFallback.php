<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Instance\Application;

/**
 * Port: the zone of the instance when APP_TIMEZONE is not set, from what a
 * module built on Instance knows. Implemented by Invoicing, from the country of
 * the first active firm; Instance never depends on it in return.
 */
interface ZoneFallback
{
    public const string TAG = 'app.zone_fallback';

    /**
     * Null when it knows none, such as before the first firm.
     */
    public function zone(): ?\DateTimeZone;
}
