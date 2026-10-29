<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text and background pairs used by the components must reach WCAG AA (4.5:1),
 * with colours read from the tokens in app.css.
 */
final class ContrastTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function pairs(): iterable
    {
        yield 'muted on white' => ['muted', 'white'];
        yield 'muted on frame' => ['muted', 'frame'];
        yield 'muted on head' => ['muted', 'head'];
        yield 'muted on badge gray' => ['muted', 'badge-gray'];
        yield 'muted on green tint' => ['muted', 'green-tint'];
        yield 'ink on white' => ['ink', 'white'];
        yield 'text on white' => ['text', 'white'];
        yield 'white on green' => ['white', 'green'];
        yield 'white on green hover' => ['white', 'green-hover'];
        yield 'green on green tint' => ['green', 'green-tint'];
        yield 'badge gray' => ['badge-gray-text', 'badge-gray'];
        yield 'badge green' => ['badge-green-text', 'badge-green'];
        yield 'badge amber' => ['badge-amber-text', 'badge-amber'];
        yield 'badge red' => ['badge-red-text', 'badge-red'];
        yield 'badge red text on white' => ['badge-red-text', 'white'];
        yield 'badge red text on head' => ['badge-red-text', 'head'];
    }

    #[DataProvider('pairs')]
    public function testPairMeetsAa(string $foreground, string $background): void
    {
        $ratio = $this->ratio($this->token($foreground), $this->token($background));

        self::assertGreaterThanOrEqual(4.5, $ratio, sprintf('%s on %s is %.2f:1', $foreground, $background, $ratio));
    }

    private function token(string $name): string
    {
        $css = file_get_contents(dirname(__DIR__, 2).'/assets/styles/app.css');
        self::assertIsString($css);
        self::assertSame(1, preg_match('/--color-'.preg_quote($name, '/').':\s*(#[0-9a-f]{6});/i', $css, $m), $name);

        return $m[1];
    }

    private function ratio(string $a, string $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(string $hex): float
    {
        $channels = array_map(static function (string $c): float {
            $v = hexdec($c) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
