<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class EmptyStateTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersGivenTextAndActions(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:EmptyState', ['icon' => 'users', 'title' => 'No contacts', 'text' => 'Add the first one.'], '<a href="/new">Add</a>');

        self::assertStringContainsString('<h2', $html);
        self::assertStringContainsString('No contacts', $html);
        self::assertStringContainsString('Add the first one.', $html);
        self::assertStringContainsString('<a href="/new">Add</a>', $html);
        self::assertStringContainsString('<svg', $html);
    }

    public function testDefaultTitleIsTranslated(): void
    {
        self::assertStringContainsString('Zatím tu nic není', (string) $this->renderTwigComponent('Ui:EmptyState'));
    }

    public function testNoTextNoParagraph(): void
    {
        self::assertStringNotContainsString('<p class', (string) $this->renderTwigComponent('Ui:EmptyState', ['title' => 'T']));
    }
}
