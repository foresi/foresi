<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Implemented by modules that show something on the start page. Every
 * implementation is picked up automatically (tag "app.dashboard_section").
 * Providers are asked only while a tenant is active; one with nothing to show
 * gives no section.
 */
#[AutoconfigureTag(DashboardSections::TAG)]
interface DashboardSectionProvider
{
    /**
     * @return iterable<DashboardSection>
     */
    public function sections(): iterable;
}
