<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Dashboard;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Twig\Attribute\AsTwigFunction;

/** Collects the sections of all providers for the start page. */
final readonly class DashboardSections
{
    public const string TAG = 'app.dashboard_section';

    /**
     * @param iterable<DashboardSectionProvider> $providers
     */
    public function __construct(
        #[AutowireIterator(self::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @param bool $tenantActive providers read tenant-owned data, so they are skipped without a tenant
     *
     * @return list<DashboardSection>
     */
    #[AsTwigFunction('dashboard_sections')]
    public function all(bool $tenantActive): array
    {
        if (!$tenantActive) {
            return [];
        }
        $sections = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->sections() as $section) {
                $sections[] = $section;
            }
        }
        usort($sections, static fn (DashboardSection $a, DashboardSection $b): int => $a->weight <=> $b->weight);

        return $sections;
    }
}
