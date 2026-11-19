<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Notice;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules that have something to tell the signed-in user on
 * every page. Every implementation is picked up automatically (tag
 * "app.shell_notice"). Providers are asked only for a signed-in person with an active membership.
 */
#[AutoconfigureTag(self::TAG)]
interface ShellNoticeProvider
{
    public const string TAG = 'app.shell_notice';

    /**
     * @return iterable<ShellNotice>
     */
    public function notices(): iterable;
}
