<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\Menu;
use Foresi\Ui\Component\MenuItem;
use Foresi\Ui\Component\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A menu row's own logic, without a kernel: its classes are compared whole.
 */
final class MenuItemTest extends TestCase
{
    private static function item(?string $href = null, string $type = 'button', ?string $icon = null, string $tone = 'default', ?bool $checked = null, bool $disabled = false, string $size = 'md'): MenuItem
    {
        $item = new MenuItem();
        $item->href = $href;
        $item->type = $type;
        $item->icon = $icon;
        $item->tone = $tone;
        $item->checked = $checked;
        $item->disabled = $disabled;
        $item->size = $size;

        return $item;
    }

    /**
     * @return iterable<string, array{MenuItem, string}>
     */
    public static function tags(): iterable
    {
        yield 'a button' => [self::item(), 'button'];
        yield 'a submit button' => [self::item(type: 'submit'), 'button'];
        yield 'a link' => [self::item(href: '/contacts'), 'a'];
        yield 'a disabled button' => [self::item(disabled: true), 'span'];
        yield 'a disabled link' => [self::item(href: '/contacts', disabled: true), 'span'];
    }

    #[DataProvider('tags')]
    public function testTheTag(MenuItem $item, string $tag): void
    {
        self::assertSame($tag, $item->tag());
    }

    /**
     * @return iterable<string, array{MenuItem, string}>
     */
    public static function looks(): iterable
    {
        yield 'default' => [self::item(), 'hover:bg-frame focus-visible:bg-frame aria-checked:bg-green-tint'];
        yield 'danger' => [self::item(tone: 'danger'), 'text-badge-red-text hover:bg-badge-red focus-visible:bg-badge-red'];
        yield 'disabled' => [self::item(disabled: true), 'cursor-default opacity-60'];
        yield 'disabled danger' => [self::item(tone: 'danger', disabled: true), 'cursor-default opacity-60'];
    }

    #[DataProvider('looks')]
    public function testTheClassesByToneAndState(MenuItem $item, string $look): void
    {
        self::assertSame(
            Menu::OPTION.' '.$look.' focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-green',
            $item->classes(),
        );
        self::assertStringEndsWith(Style::FIELD_FOCUS, $item->classes());
    }

    public function testTheCompactRowOfADataTableMenu(): void
    {
        self::assertStringStartsWith(Menu::OPTION_SM.' ', self::item(size: 'sm')->classes());
        self::assertStringContainsString('min-h-8', Menu::OPTION_SM);
    }

    /**
     * @return iterable<string, array{MenuItem}>
     */
    public static function validItems(): iterable
    {
        yield 'a button' => [self::item()];
        yield 'a submit button with an icon' => [self::item(type: 'submit', icon: 'plus')];
        yield 'a link' => [self::item(href: '/contacts')];
        yield 'a checked danger row' => [self::item(tone: 'danger', checked: true)];
    }

    #[DataProvider('validItems')]
    public function testAValidItemPasses(MenuItem $item): void
    {
        $item->validate();
        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{MenuItem, string}>
     */
    public static function invalidItems(): iterable
    {
        yield 'an unknown type' => [self::item(type: 'reset'), 'Unknown menu item type "reset", expected button or submit.'];
        yield 'an unknown icon' => [self::item(icon: 'rocket'), 'Icon "rocket" is not in the locked icon set.'];
        yield 'an unsafe href' => [self::item(href: '/a b'), 'Unsafe href.'];
        yield 'an unknown tone' => [self::item(tone: 'loud'), 'Unknown menu item tone "loud", expected one of: default, danger.'];
        yield 'an unknown size' => [self::item(size: 'lg'), 'Unknown menu item size "lg", expected md or sm.'];
    }

    #[DataProvider('invalidItems')]
    public function testAnInvalidItemThrows(MenuItem $item, string $message): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException($message));
        $item->validate();
    }
}
