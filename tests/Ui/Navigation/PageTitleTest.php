<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Navigation;

use Foresi\Ui\Navigation\NavigationItem;
use Foresi\Ui\Navigation\NavigationItemProvider;
use Foresi\Ui\Navigation\NavigationRegistry;
use Foresi\Ui\Navigation\PageTitle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

final class PageTitleTest extends TestCase
{
    #[DataProvider('titles')]
    public function testTitleRunsFromThePageToTheProduct(string $page, ?string $route, string $expected): void
    {
        self::assertSame($expected, $this->title()->compose($page, $route));
    }

    /**
     * @return iterable<string, array{string, string|null, string}>
     */
    public static function titles(): iterable
    {
        yield 'record in a section' => ['Fix the login', 'app_task', 'Fix the login · Projects · Foresi'];
        yield 'list page is its section' => ['Projects', 'app_projects', 'Projects · Foresi'];
        yield 'section compared without case' => ['projects', 'app_projects', 'projects · Foresi'];
        yield 'page outside the navigation' => ['Sign in', 'app_login', 'Sign in · Foresi'];
        yield 'frame without a route' => ['Sign in', null, 'Sign in · Foresi'];
        yield 'page without a heading' => ['', 'app_task', 'Projects · Foresi'];
        yield 'surrounding space trimmed' => ["  Fix the login\n", null, 'Fix the login · Foresi'];
    }

    private function title(): PageTitle
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['nav.item.projects' => 'Projects'], 'en');
        $provider = new readonly class implements NavigationItemProvider {
            public function items(): iterable
            {
                yield new NavigationItem('work', 'nav.item.projects', 'columns-3', 'app_projects', routePrefixes: ['app_project', 'app_task']);
            }
        };

        return new PageTitle(new NavigationRegistry([$provider]), $translator, 'Foresi');
    }
}
