<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui;

use App\Ui\Component\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StyleTest extends TestCase
{
    public function testLockedIconAndNullAreAccepted(): void
    {
        Style::icon('plus');
        Style::icon(null);
        $this->expectNotToPerformAssertions();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIcons(): iterable
    {
        yield 'unknown' => ['rocket'];
        yield 'traversal' => ['../lucide/plus'];
        yield 'empty' => [''];
        yield 'uppercase' => ['Plus'];
    }

    #[DataProvider('badIcons')]
    public function testUnknownIconThrows(string $icon): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Style::icon($icon);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badHrefs(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'data' => ['data:text/html,x'];
        yield 'whitespace' => ['/a b'];
        yield 'control' => ["java\tscript:alert(1)"];
    }

    #[DataProvider('badHrefs')]
    public function testUnsafeHrefThrows(string $href): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Style::href($href);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function goodHrefs(): iterable
    {
        yield 'relative' => ['/contacts?x=1'];
        yield 'fragment' => ['#top'];
        yield 'https' => ['https://example.com/a'];
        yield 'http' => ['HTTP://example.com'];
        yield 'mailto' => ['mailto:testing@example.com'];
        yield 'tel' => ['tel:+420123456789'];
    }

    #[DataProvider('goodHrefs')]
    public function testSafeHrefPasses(string $href): void
    {
        Style::href($href);
        Style::href(null);
        $this->expectNotToPerformAssertions();
    }
}
