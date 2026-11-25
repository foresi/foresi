<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Navigation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules that bring a business area of their own. Every
 * implementation is picked up automatically (tag "foresi.ui.navigation_area").
 */
#[AutoconfigureTag(NavigationArea::TAG)]
interface NavigationAreaProvider
{
    /**
     * @return iterable<NavigationArea>
     */
    public function areas(): iterable;
}
