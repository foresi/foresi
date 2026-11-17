<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

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
        yield 'protocol relative' => ['//evil.example'];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'slash backslash' => ['/\\evil.example'];
        yield 'backslash slash' => ['\\/evil.example'];
        yield 'scheme backslash' => ['https:\\\\evil'];
        yield 'scheme without slashes' => ['http:evil.com'];
        yield 'scheme without host' => ['https:///x'];
        yield 'single backslash' => ['/a\\b'];
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
        yield 'bare relative' => ['x/y'];
        yield 'query' => ['?q=1'];
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

    public function testAFieldWearsTheSoftEdgeOrRedWhenInvalid(): void
    {
        $rest = ' disabled:border-divider disabled:bg-head disabled:text-muted focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-green';
        $base = 'w-full rounded-control border bg-white px-3 text-ink placeholder:text-muted transition-colors ';

        self::assertSame($base.'border-control-line'.$rest, Style::field(false));
        self::assertSame($base.'border-badge-red-text'.$rest, Style::field(true));
    }

    /**
     * @return iterable<string, array{string, string, bool, bool, string}>
     */
    public static function buttons(): iterable
    {
        yield 'primary' => ['primary', 'md', false, false, 'bg-green border-green text-white hover:bg-green-hover h-control px-3.5'];
        yield 'secondary' => ['secondary', 'md', false, false, 'bg-white border-control-line text-ink hover:bg-head h-control px-3.5'];
        yield 'ghost' => ['ghost', 'md', false, false, 'bg-transparent border-transparent text-text hover:bg-head h-control px-3.5'];
        yield 'danger' => ['danger', 'md', false, false, 'bg-white border-control-line text-badge-red-text hover:bg-badge-red h-control px-3.5'];
        yield 'small' => ['secondary', 'sm', false, false, 'bg-white border-control-line text-ink hover:bg-head h-control-sm text-small px-2.5'];
        yield 'icon only' => ['secondary', 'md', true, false, 'bg-white border-control-line text-ink hover:bg-head h-control w-control'];
        yield 'small icon only' => ['ghost', 'sm', true, false, 'bg-transparent border-transparent text-text hover:bg-head h-control-sm text-small w-control-sm'];
        yield 'pressed' => ['secondary', 'md', false, true, 'bg-green-tint border-green text-green h-control px-3.5'];
    }

    #[DataProvider('buttons')]
    public function testTheButtonClasses(string $variant, string $size, bool $iconOnly, bool $pressed, string $classes): void
    {
        self::assertSame(
            'inline-flex items-center justify-center gap-1.5 rounded-control border font-semibold whitespace-nowrap cursor-pointer no-underline transition-colors aria-disabled:opacity-50 disabled:opacity-50 disabled:cursor-not-allowed '
                .$classes.' focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green',
            Style::button($variant, $size, $iconOnly, $pressed),
        );
    }

    public function testTheButtonIconSizes(): void
    {
        self::assertSame('size-5 shrink-0', Style::buttonIcon('md'));
        self::assertSame('size-4 shrink-0', Style::buttonIcon('sm'));
    }

    public function testAnUnknownButtonIconSizeThrows(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Unknown size "xl", expected one of: md, sm.'));
        Style::buttonIcon('xl');
    }
}
