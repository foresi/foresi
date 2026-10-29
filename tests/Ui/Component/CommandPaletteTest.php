<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class CommandPaletteTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return list<array<string, mixed>>
     */
    private function commands(): array
    {
        return [
            ['label' => 'New contact', 'href' => '/contacts/new', 'group' => 'Create', 'icon' => 'plus', 'shortcut' => 'N', 'keywords' => 'add party'],
            ['label' => 'Contacts', 'href' => '/contacts', 'group' => 'Go to', 'icon' => 'users'],
            ['label' => 'Settings', 'href' => '/settings', 'group' => 'Go to'],
            ['label' => 'Help', 'href' => '/help'],
        ];
    }

    private function render(): string
    {
        return (string) $this->renderTwigComponent('Ui:CommandPalette', ['commands' => $this->commands()]);
    }

    public function testOpenerIsAButtonAnnouncingTheDialogAndShortcut(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-controller="command-palette"', $html);
        self::assertStringContainsString('aria-haspopup="dialog"', $html);
        self::assertStringContainsString('aria-keyshortcuts="Control+K Meta+K"', $html);
        self::assertStringContainsString('data-action="command-palette#open"', $html);
        self::assertStringContainsString('<kbd', $html);
        self::assertStringContainsString('data-command-palette-target="shortcut"', $html);
        self::assertStringContainsString('Ctrl K', $html);
    }

    public function testDialogIsHiddenModalAndLabelled(): void
    {
        $html = $this->render();

        self::assertMatchesRegularExpression('#<div[^>]*data-command-palette-target="overlay"[^>]*hidden|<div[^>]*hidden[^>]*data-command-palette-target="overlay"#', $html);
        self::assertStringContainsString('role="dialog"', $html);
        self::assertStringContainsString('aria-modal="true"', $html);
        self::assertStringContainsString('tabindex="-1"', $html);
        self::assertStringContainsString('data-action="mousedown->command-palette#keepFocus"', $html);
        self::assertStringContainsString('aria-label="Příkazová paleta"', $html);
    }

    public function testInputIsAComboboxControllingTheListbox(): void
    {
        $html = $this->render();

        self::assertStringContainsString('role="combobox"', $html);
        self::assertStringContainsString('aria-expanded="false"', $html);
        self::assertStringContainsString('aria-controls="command-palette-list"', $html);
        self::assertStringContainsString('aria-autocomplete="list"', $html);
        self::assertStringContainsString('id="command-palette-list"', $html);
        self::assertStringContainsString('role="listbox"', $html);
    }

    public function testCommandsAreGroupedOptionsWithHrefAndIds(): void
    {
        $html = $this->render();

        self::assertSame(4, substr_count($html, 'role="option"'));
        self::assertSame(3, substr_count($html, 'role="group"'));
        self::assertStringContainsString('id="command-palette-option-0"', $html);
        self::assertStringContainsString('id="command-palette-option-3"', $html);
        self::assertStringContainsString('data-href="/contacts/new"', $html);
        self::assertStringContainsString('data-keywords="add party"', $html);
        self::assertStringContainsString('aria-selected="false"', $html);
        self::assertStringContainsString('Create', $html);
        self::assertStringContainsString('Go to', $html);
    }

    public function testEmptyMessageIsPresentAndHidden(): void
    {
        $html = $this->render();

        self::assertStringContainsString('data-command-palette-target="empty"', $html);
        self::assertStringContainsString('Nic nenalezeno', $html);
    }

    public function testUnsafeCommandHrefThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:CommandPalette', ['commands' => [['label' => 'x', 'href' => 'javascript:alert(1)']]]);
    }

    public function testCommandWithoutLabelThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:CommandPalette', ['commands' => [['href' => '/x']]]);
    }

    public function testUnknownIconThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:CommandPalette', ['commands' => [['label' => 'x', 'href' => '/x', 'icon' => 'nope']]]);
    }
}
