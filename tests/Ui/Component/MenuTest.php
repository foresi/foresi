<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use App\Ui\Component\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The menu's own logic, without a kernel: its classes are compared whole, so a missing or
 * reordered class shows.
 */
final class MenuTest extends TestCase
{
    private const string PANEL = 'rounded-control border border-container bg-white p-1 shadow-floating';

    /**
     * @param (\Closure(Menu): void)|null $set
     */
    private function menu(?\Closure $set = null): Menu
    {
        $menu = new Menu();
        $menu->id = 'actions';
        $menu->label = 'Actions';
        if (null !== $set) {
            $set($menu);
        }

        return $menu;
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function positions(): iterable
    {
        yield 'below at the end' => ['below', 'end', 'top-full mt-1 right-0'];
        yield 'below at the start' => ['below', 'start', 'top-full mt-1 left-0'];
        yield 'above at the end' => ['above', 'end', 'bottom-full mb-1 right-0'];
        yield 'above at the start' => ['above', 'start', 'bottom-full mb-1 left-0'];
    }

    #[DataProvider('positions')]
    public function testThePanelIsPlacedByPlacementAndAlign(string $placement, string $align, string $position): void
    {
        $menu = $this->menu(static function (Menu $menu) use ($placement, $align): void {
            $menu->placement = $placement;
            $menu->align = $align;
        });

        self::assertSame('absolute z-50 w-max min-w-40 text-left '.$position.' '.self::PANEL, $menu->panelClasses());
    }

    public function testTheMenuClassWidensThePanel(): void
    {
        $menu = $this->menu(static function (Menu $menu): void {
            $menu->menuClass = 'w-72';
        });

        self::assertSame('absolute z-50 w-max min-w-40 text-left top-full mt-1 right-0 '.self::PANEL.' w-72', $menu->panelClasses());
    }

    public function testTheTriggerIdFollowsTheMenuIdUnlessGiven(): void
    {
        self::assertSame('actions-button', $this->menu()->triggerId());
        self::assertSame('more', $this->menu(static function (Menu $menu): void {
            $menu->buttonId = 'more';
        })->triggerId());
    }

    public function testTheChevronIsSizedAsAButtonIcon(): void
    {
        self::assertSame('size-5 shrink-0 text-muted', $this->menu()->chevronClasses());
        self::assertSame('size-4 shrink-0 text-muted', $this->menu(static function (Menu $menu): void {
            $menu->size = 'sm';
        })->chevronClasses());
    }

    /**
     * @return iterable<string, array{\Closure(Menu): void}>
     */
    public static function validMenus(): iterable
    {
        yield 'plain' => [static function (Menu $menu): void {}];
        yield 'icon only' => [static function (Menu $menu): void {
            $menu->iconOnly = true;
            $menu->icon = 'ellipsis';
        }];
        yield 'chevron down' => [static function (Menu $menu): void {
            $menu->chevron = 'down';
        }];
        yield 'chevron up, above, at the start' => [static function (Menu $menu): void {
            $menu->chevron = 'up';
            $menu->placement = 'above';
            $menu->align = 'start';
        }];
        yield 'small ghost' => [static function (Menu $menu): void {
            $menu->variant = 'ghost';
            $menu->size = 'sm';
        }];
    }

    /**
     * @param \Closure(Menu): void $set
     */
    #[DataProvider('validMenus')]
    public function testAValidMenuPasses(\Closure $set): void
    {
        $this->menu($set)->validate();
        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{\Closure(Menu): void, string}>
     */
    public static function invalidMenus(): iterable
    {
        yield 'no id' => [static function (Menu $menu): void {
            $menu->id = '';
        }, 'A menu needs an id.'];
        yield 'no label' => [static function (Menu $menu): void {
            $menu->label = '';
        }, 'A menu needs a label for its trigger.'];
        yield 'a blank label' => [static function (Menu $menu): void {
            $menu->label = '  ';
        }, 'A menu needs a label for its trigger.'];
        yield 'icon only without an icon' => [static function (Menu $menu): void {
            $menu->iconOnly = true;
        }, 'An icon-only menu trigger needs an icon.'];
        yield 'an unknown chevron' => [static function (Menu $menu): void {
            $menu->chevron = 'left';
        }, 'Unknown chevron "left", expected down or up.'];
        yield 'an unknown variant' => [static function (Menu $menu): void {
            $menu->variant = 'loud';
        }, 'Unknown variant "loud", expected one of: primary, secondary, ghost, danger.'];
        yield 'an unknown size' => [static function (Menu $menu): void {
            $menu->size = 'xl';
        }, 'Unknown size "xl", expected one of: md, sm.'];
        yield 'an unknown icon' => [static function (Menu $menu): void {
            $menu->icon = 'rocket';
        }, 'Icon "rocket" is not in the locked icon set.'];
        yield 'an unknown placement' => [static function (Menu $menu): void {
            $menu->placement = 'left';
        }, 'Unknown placement "left", expected below or above.'];
        yield 'an unknown align' => [static function (Menu $menu): void {
            $menu->align = 'center';
        }, 'Unknown align "center", expected end or start.'];
    }

    /**
     * @param \Closure(Menu): void $set
     */
    #[DataProvider('invalidMenus')]
    public function testAnInvalidMenuThrows(\Closure $set, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));
        $this->menu($set)->validate();
    }
}
