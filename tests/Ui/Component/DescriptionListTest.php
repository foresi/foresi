<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class DescriptionListTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testRendersLabelsValuesBadgesAndNotes(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:DescriptionList', ['items' => [
            ['label' => 'Currency', 'value' => 'CZK'],
            ['label' => 'VIES', 'badge' => ['label' => 'Valid', 'tone' => 'green'], 'note' => 'Checked today'],
        ]]);

        self::assertSame(2, substr_count($html, '<dt'));
        self::assertSame(2, substr_count($html, '<dd'));
        self::assertStringContainsString('CZK', $html);
        self::assertStringContainsString('bg-badge-green', $html);
        self::assertStringContainsString('Checked today', $html);
    }

    public function testRendersNothingWithoutItems(): void
    {
        self::assertSame('', trim((string) $this->renderTwigComponent('Ui:DescriptionList', ['items' => []])));
    }

    public function testNeedsAValueOrABadge(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:DescriptionList', ['items' => [['label' => 'Currency']]]);
    }

    public function testRejectsANoteThatIsNotText(): void
    {
        $this->expectException(\Throwable::class);
        $this->renderTwigComponent('Ui:DescriptionList', ['items' => [['label' => 'Currency', 'value' => 'CZK', 'note' => 3]]]);
    }

    public function testCardRendersItsActionsInTheHeader(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Card', ['title' => 'Checks'], blocks: ['actions' => '<button>Again</button>', 'content' => 'Body']);

        self::assertMatchesRegularExpression('#</h3>\s*<div class="-my-1.5 ml-auto[^"]*"><button>Again</button></div>#', $html);
    }
}
