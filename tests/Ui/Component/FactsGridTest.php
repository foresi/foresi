<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class FactsGridTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersDefinitionListWithPairs(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => [
            ['label' => 'Reg. no.', 'value' => '12345678'],
            ['label' => 'E-mail', 'value' => 'info@example.com'],
        ]]);

        self::assertStringContainsString('<dl', $html);
        self::assertSame(2, substr_count($html, '<dt'));
        self::assertSame(2, substr_count($html, '<dd'));
        self::assertStringContainsString('12345678', $html);
        self::assertStringContainsString('--n:2', $html);
    }

    public function testColumnsAreCappedAtSix(): void
    {
        $items = array_map(static fn (int $i): array => ['label' => 'L'.$i, 'value' => 'V'.$i], range(1, 9));
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => $items]);

        self::assertStringContainsString('--n:6', $html);
    }

    public function testLongValuesAreTruncatedWithTitle(): void
    {
        $email = str_repeat('x', 60).'@example.com';
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => [['label' => 'E-mail', 'value' => $email]]]);

        self::assertStringContainsString('title="'.$email.'"', $html);
        self::assertStringContainsString('truncate', $html);
    }

    public function testBigValueIsEmphasisedAndTabular(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => [['label' => 'Balance', 'value' => '9 000', 'big' => true]]]);

        self::assertStringContainsString('text-lg', $html);
        self::assertStringContainsString('num', $html);
    }

    public function testNormalValueIsNotBig(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => [['label' => 'A', 'value' => 'b']]]);

        self::assertStringNotContainsString('text-lg', $html);
    }

    public function testAValueWithAnHrefIsALinkThatOpensNoReferrer(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => [
            ['label' => 'Website', 'value' => 'example.com', 'href' => 'https://example.com'],
            ['label' => 'Phone', 'value' => '+420 123 456 789'],
        ]]);

        self::assertSame(1, substr_count($html, '<a '));
        self::assertStringContainsString('<a href="https://example.com" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('>example.com</a>', $html);
        self::assertStringNotContainsString('target=', $html);
    }

    public function testValuesAreEscaped(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => [['label' => 'A', 'value' => '<i>b</i>']]]);

        self::assertStringNotContainsString('<i>', $html);
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>}>
     */
    public static function invalid(): iterable
    {
        yield 'no label' => [[['value' => 'x']]];
        yield 'no value' => [[['label' => 'x']]];
        yield 'non-string value' => [[['label' => 'x', 'value' => ['a']]]];
        yield 'non-string href' => [[['label' => 'x', 'value' => 'y', 'href' => ['a']]]];
        yield 'unsafe href' => [[['label' => 'x', 'value' => 'y', 'href' => 'javascript:alert(1)']]];
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidItemsThrow(array $items): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:FactsGrid', ['items' => $items]);
    }

    public function testEmptyItemsRenderNothing(): void
    {
        self::assertSame('', trim((string) $this->renderTwigComponent('Ui:FactsGrid', ['items' => []])));
    }
}
