<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Dashboard;

use App\Ui\Dashboard\DashboardSection;
use App\Ui\Dashboard\DashboardSectionProvider;
use App\Ui\Dashboard\DashboardSections;
use PHPUnit\Framework\TestCase;

final class DashboardSectionsTest extends TestCase
{
    public function testCollectsTheSectionsOfAllProvidersLightestFirst(): void
    {
        $sections = new DashboardSections([
            $this->provider(new DashboardSection('b.html.twig', weight: 20), new DashboardSection('a.html.twig', ['x' => 1], 10)),
            $this->provider(),
            $this->provider(new DashboardSection('c.html.twig', weight: 15)),
        ]);

        self::assertSame(['a.html.twig', 'c.html.twig', 'b.html.twig'], array_map(static fn (DashboardSection $section): string => $section->template, $sections->all(true)));
        self::assertSame(['x' => 1], $sections->all(true)[0]->context);
    }

    public function testAsksNoProviderWithoutATenant(): void
    {
        $provider = new class implements DashboardSectionProvider {
            public function sections(): iterable
            {
                throw new \LogicException('A provider is asked only with a tenant.');
            }
        };

        self::assertSame([], new DashboardSections([$provider])->all(false));
    }

    public function testASectionNeedsATemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DashboardSection('');
    }

    private function provider(DashboardSection ...$sections): DashboardSectionProvider
    {
        return new readonly class(array_values($sections)) implements DashboardSectionProvider {
            /**
             * @param list<DashboardSection> $sections
             */
            public function __construct(private array $sections)
            {
            }

            public function sections(): iterable
            {
                yield from $this->sections;
            }
        };
    }
}
