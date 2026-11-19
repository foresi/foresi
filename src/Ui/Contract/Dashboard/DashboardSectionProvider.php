<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Contract\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules that show something on the start page. Every
 * implementation is picked up automatically (tag "app.dashboard_section").
 * Providers are asked only for a signed-in person with an active membership; one with nothing to show
 * gives no section.
 */
#[AutoconfigureTag(self::TAG)]
interface DashboardSectionProvider
{
    public const string TAG = 'app.dashboard_section';

    /**
     * @return iterable<DashboardSection>
     */
    public function sections(): iterable;
}
