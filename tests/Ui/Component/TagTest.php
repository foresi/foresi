<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class TagTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersOutlinedTagWithDot(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Tag', ['label' => 'Partner', 'color' => '#2f5e3d']);

        self::assertStringContainsString('border-container', $html);
        self::assertStringContainsString('background-color: #2f5e3d', $html);
        self::assertStringContainsString('aria-hidden="true"', $html);
        self::assertStringContainsString('title="Partner"', $html);
        self::assertStringContainsString('truncate', $html);
    }

    public function testShortHexAccepted(): void
    {
        self::assertStringContainsString('#abc', (string) $this->renderTwigComponent('Ui:Tag', ['label' => 'A', 'color' => '#abc']));
    }

    public function testInvalidColourThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Tag', ['label' => 'A', 'color' => 'red; background: url(x)']);
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testColourMustBeAnchoredHex(): void
    {
        foreach (['x#abc', '#abcd', '#abc;'] as $color) {
            try {
                $this->mountTwigComponent('Ui:Tag', ['label' => 'A', 'color' => $color]);
                self::fail('Expected rejection of '.$color);
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
