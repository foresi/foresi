<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class BadgeTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tones(): iterable
    {
        yield 'gray' => ['gray', 'bg-badge-gray text-badge-gray-text'];
        yield 'green' => ['green', 'bg-badge-green text-badge-green-text'];
        yield 'amber' => ['amber', 'bg-badge-amber text-badge-amber-text'];
        yield 'red' => ['red', 'bg-badge-red text-badge-red-text'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tones')]
    public function testToneClasses(string $tone, string $expected): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Badge', ['tone' => $tone], 'Paid');

        self::assertStringContainsString($expected, $html);
        self::assertStringContainsString('Paid', $html);
    }

    public function testDefaultToneIsGray(): void
    {
        self::assertStringContainsString('bg-badge-gray', (string) $this->renderTwigComponent('Ui:Badge', [], 'X'));
    }

    public function testUnknownToneThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Badge', ['tone' => 'purple']);
    }
}
