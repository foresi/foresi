<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Ui\Navigation;

use Twig\Attribute\AsTwigFunction;

final readonly class NavigationTwigFunction
{
    public function __construct(private NavigationRegistry $registry)
    {
    }

    /**
     * @return list<array{key: string, labelKey: string, items: list<NavigationEntry>}>
     */
    #[AsTwigFunction('nav_groups')]
    public function groups(?string $currentRoute, bool $tenantActive): array
    {
        return $this->registry->groups($currentRoute, $tenantActive);
    }
}
