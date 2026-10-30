<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class KbdTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersKbd(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Kbd', [], 'F');

        self::assertStringStartsWith('<kbd', $html);
        self::assertStringContainsString('F', $html);
        self::assertStringContainsString('border-frame-line', $html);
    }
}
