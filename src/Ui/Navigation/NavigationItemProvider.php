<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules and plugins to put items into the sidebar. Every
 * implementation is picked up automatically (tag "app.navigation_item").
 */
#[AutoconfigureTag(NavigationRegistry::TAG)]
interface NavigationItemProvider
{
    /**
     * @return iterable<NavigationItem>
     */
    public function items(): iterable;
}
