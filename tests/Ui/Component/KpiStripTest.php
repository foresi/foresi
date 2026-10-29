<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class KpiStripTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return list<array<string, mixed>>
     */
    private function items(): array
    {
        return [
            ['label' => 'Open', 'value' => '12', 'note' => 'this month'],
            ['label' => 'Overdue', 'value' => '3', 'badge' => ['label' => 'Late', 'tone' => 'red']],
            ['label' => 'Paid', 'value' => '1 200'],
            ['label' => 'Draft', 'value' => '0'],
        ];
    }

    public function testRendersFourItemsWithTabularNumbers(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:KpiStrip', ['items' => $this->items()]);

        self::assertSame(4, substr_count($html, 'data-kpi'));
        self::assertSame(4, substr_count($html, 'class="num '));
        self::assertStringContainsString('1 200', $html);
        self::assertStringContainsString('this month', $html);
        self::assertStringContainsString('--n:4', $html);
    }

    public function testBadgeIsRendered(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:KpiStrip', ['items' => $this->items()]);

        self::assertStringContainsString('bg-badge-red', $html);
        self::assertStringContainsString('Late', $html);
        self::assertSame(1, substr_count($html, 'bg-badge-'));
    }

    public function testColumnCountFollowsItems(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:KpiStrip', ['items' => array_slice($this->items(), 0, 2)]);

        self::assertStringContainsString('--n:2', $html);
        self::assertSame(2, substr_count($html, 'data-kpi'));
    }

    public function testValuesAreEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:KpiStrip', ['items' => [['label' => '<b>x</b>', 'value' => '1']]]);

        self::assertStringNotContainsString('<b>x</b>', $html);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function invalid(): iterable
    {
        yield 'none' => [[]];
        yield 'five' => [array_fill(0, 5, ['label' => 'a', 'value' => '1'])];
        yield 'no label' => [[['value' => '1']]];
        yield 'no value' => [[['label' => 'a']]];
        yield 'bad badge' => [[['label' => 'a', 'value' => '1', 'badge' => ['tone' => 'red']]]];
        yield 'bad badge tone' => [[['label' => 'a', 'value' => '1', 'badge' => ['label' => 'x', 'tone' => 'pink']]]];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidItemsThrow(array $items): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:KpiStrip', ['items' => $items]);
    }
}
