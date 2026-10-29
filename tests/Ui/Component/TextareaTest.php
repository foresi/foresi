<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class TextareaTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersTextarea(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Textarea', ['name' => 'note', 'label' => 'Note', 'value' => 'Hello', 'rows' => 6, 'required' => true, 'error' => 'Too short']);

        self::assertStringContainsString('<textarea', $html);
        self::assertStringContainsString('rows="6"', $html);
        self::assertStringContainsString('>Hello</textarea>', $html);
        self::assertStringContainsString(' required', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('aria-describedby="note-error"', $html);
    }

    public function testDefaultRows(): void
    {
        self::assertStringContainsString('rows="4"', (string) $this->renderTwigComponent('Ui:Textarea', ['name' => 'n', 'label' => 'N']));
    }
}
