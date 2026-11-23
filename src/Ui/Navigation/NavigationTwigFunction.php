<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Ui\Navigation;

use Foresi\Ui\Contract\Navigation\NavigationArea;
use Twig\Attribute\AsTwigFunction;

final readonly class NavigationTwigFunction
{
    public function __construct(private NavigationRegistry $registry)
    {
    }

    /**
     * @return list<array{area: NavigationArea, current: bool, headings: bool, sections: list<array{key: string, labelKey: string, items: list<NavigationEntry>}>}>
     */
    #[AsTwigFunction('nav_areas')]
    public function areas(?string $currentRoute, bool $member): array
    {
        return $this->registry->areas($currentRoute, $member);
    }
}
