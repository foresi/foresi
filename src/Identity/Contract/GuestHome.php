<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The start page of a guest, offered by the module that shares things with guests. With no
 * provider installed a guest starts on the dashboard, which shows a guest nothing but its empty state.
 */
#[AutoconfigureTag(self::TAG)]
interface GuestHome
{
    public const string TAG = 'app.guest_home';

    /** The route a guest is sent to from the dashboard; it takes no parameters. */
    public function route(): string;
}
