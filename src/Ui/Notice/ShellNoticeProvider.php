<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Notice;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules that have something to tell the signed-in user on
 * every page. Every implementation is picked up automatically (tag
 * "app.shell_notice"). Providers are asked only while a tenant is active.
 */
#[AutoconfigureTag(ShellNotices::TAG)]
interface ShellNoticeProvider
{
    /**
     * @return iterable<ShellNotice>
     */
    public function notices(): iterable;
}
