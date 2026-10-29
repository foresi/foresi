<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PaginationTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private function render(int $page, int $pages, string $baseUrl = '/contacts'): string
    {
        return (string) $this->renderTwigComponent('Ui:Pagination', ['page' => $page, 'pages' => $pages, 'baseUrl' => $baseUrl]);
    }

    public function testSinglePageRendersNothing(): void
    {
        self::assertSame('', trim($this->render(1, 1)));
        self::assertSame('', trim($this->render(1, 0)));
    }

    public function testCurrentPageHasAriaCurrentAndNeighboursAreLinks(): void
    {
        $html = $this->render(2, 3);

        self::assertSame(1, substr_count($html, 'aria-current="page"'));
        self::assertStringContainsString('href="/contacts?page=1"', $html);
        self::assertStringContainsString('href="/contacts?page=3"', $html);
        self::assertStringContainsString('<nav', $html);
        self::assertStringContainsString('aria-label="Stránkování"', $html);
    }

    public function testPreviousAndNextHaveAriaLabels(): void
    {
        $html = $this->render(2, 3);

        self::assertStringContainsString('aria-label="Předchozí stránka"', $html);
        self::assertStringContainsString('aria-label="Další stránka"', $html);
        self::assertStringContainsString('href="/contacts?page=1" aria-label="Předchozí stránka"', $html);
        self::assertStringContainsString('href="/contacts?page=3" aria-label="Další stránka"', $html);
    }

    public function testPreviousIsDisabledOnFirstPageAndNextOnLast(): void
    {
        $first = $this->render(1, 3);
        self::assertStringContainsString('aria-disabled="true"', $first);
        self::assertStringContainsString('<span class="sr-only">Předchozí stránka</span>', $first);
        self::assertStringNotContainsString('<span aria-disabled="true" aria-label', $first);
        self::assertStringNotContainsString('href="/contacts?page=0"', $first);

        $last = $this->render(3, 3);
        self::assertStringContainsString('<span class="sr-only">Další stránka</span>', $last);
        self::assertStringNotContainsString('page=4', $last);
    }

    public function testManyPagesAreCollapsedWithGaps(): void
    {
        $html = $this->render(10, 20);

        self::assertSame(2, substr_count($html, '…'));
        self::assertStringContainsString('href="/contacts?page=1"', $html);
        self::assertStringContainsString('href="/contacts?page=9"', $html);
        self::assertStringContainsString('href="/contacts?page=11"', $html);
        self::assertStringContainsString('href="/contacts?page=20"', $html);
        self::assertStringNotContainsString('href="/contacts?page=5"', $html);
    }

    public function testNearTheStartHasOneGap(): void
    {
        self::assertSame(1, substr_count($this->render(2, 20), '…'));
        self::assertSame(1, substr_count($this->render(19, 20), '…'));
    }

    public function testNoGapForAdjacentPages(): void
    {
        self::assertStringNotContainsString('…', $this->render(3, 5));
    }

    public function testPageLinksHaveAccessibleNames(): void
    {
        self::assertStringContainsString('aria-label="Stránka 3"', $this->render(2, 5));
    }

    public function testExistingQueryStringIsExtended(): void
    {
        self::assertStringContainsString('href="/contacts?q=abc&amp;page=2"', $this->render(1, 3, '/contacts?q=abc'));
    }

    public function testOutOfRangePageIsClamped(): void
    {
        $html = $this->render(99, 3);

        self::assertStringContainsString('<span class="sr-only">Další stránka</span>', $html);
        self::assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    public function testUnsafeBaseUrlThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Pagination', ['page' => 1, 'pages' => 3, 'baseUrl' => 'javascript:alert(1)']);
    }
}
