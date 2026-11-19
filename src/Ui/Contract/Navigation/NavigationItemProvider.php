<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules and plugins to put items into the sidebar. Every
 * implementation is picked up automatically (tag "app.navigation_item").
 */
#[AutoconfigureTag(self::TAG)]
interface NavigationItemProvider
{
    public const string TAG = 'app.navigation_item';

    /**
     * @return iterable<NavigationItem>
     */
    public function items(): iterable;
}
