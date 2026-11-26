<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Identity\Infrastructure\Navigation;

use Foresi\Identity\Infrastructure\Controller\ProfileController;
use Foresi\Identity\Infrastructure\Controller\TwoFactorSetupController;
use Foresi\Ui\Contract\Navigation\CoreNavigationAreas;
use Foresi\Ui\Contract\Navigation\NavigationArea;
use Foresi\Ui\Contract\Navigation\NavigationItem;
use Foresi\Ui\Contract\Navigation\NavigationItemProvider;

/**
 * Identity's part of the person's own account, for every role: the profile and
 * the two-factor sign-in. The other modules add theirs to the same area.
 */
final readonly class ProfileNavigation implements NavigationItemProvider
{
    public function items(): iterable
    {
        yield new NavigationItem(NavigationArea::PROFILE, 'nav.item.profile', 'user', ProfileController::ROUTE, weight: 10, routePrefixes: ['app_account_profile'], section: CoreNavigationAreas::SECTION_PROFILE);
        yield new NavigationItem(NavigationArea::PROFILE, 'nav.item.two_factor', 'lock', TwoFactorSetupController::SETUP_ROUTE, weight: 20, routePrefixes: ['app_two_factor'], section: CoreNavigationAreas::SECTION_SECURITY);
    }
}
