<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class SheetTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props = [], string $footer = '<button type="button">Close</button>'): Crawler
    {
        $html = (string) $this->renderTwigComponent('Ui:Sheet', ['id' => 'test-sheet', 'title' => 'Invoice 20260001'] + $props, '<p>Body</p>', ['footer' => $footer]);

        return new Crawler($html);
    }

    public function testItIsANativeDialogInTheSheetLayerLabelledByItsTitle(): void
    {
        $sheet = $this->render()->filter('dialog#test-sheet');

        self::assertCount(1, $sheet);
        self::assertSame('dialog sheet', $sheet->attr('data-controller'));
        self::assertSame('sheet', $sheet->attr('data-dialog-layer-value'));
        self::assertSame('a[data-sheet]', $sheet->attr('data-dialog-link-value'));
        self::assertSame('dialog#sheet-page', $sheet->attr('data-dialog-source-value'));
        self::assertNotNull($sheet->attr('data-sheet'));
        self::assertSame('test-sheet-title', $sheet->attr('aria-labelledby'));
        self::assertSame('Invoice 20260001', $sheet->filter('h2#test-sheet-title')->text());
        self::assertNull($sheet->attr('open'));
    }

    public function testItSitsAtTheRightEdgeOverALightScrimAndFillsAPhone(): void
    {
        $class = ' '.$this->render()->filter('dialog')->attr('class').' ';

        foreach (['inset-y-0', 'right-0', 'left-auto', 'm-0', 'border-l', 'open:flex', 'backdrop:bg-ink/25', 'max-sm:w-full'] as $expected) {
            self::assertStringContainsString(' '.$expected.' ', $class);
        }
    }

    public function testTwoWidthsFiltersAndDetail(): void
    {
        self::assertStringContainsString('w-[400px]', (string) $this->render(['size' => 'sm'])->filter('dialog')->attr('class'));
        self::assertStringContainsString('w-[640px]', (string) $this->render()->filter('dialog')->attr('class'));
    }

    public function testUnknownSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Unknown size "lg", expected one of: sm, md.');
        $this->mountTwigComponent('Ui:Sheet', ['id' => 'x', 'title' => 'x', 'size' => 'lg']);
    }

    public function testATitleIsRequiredUnlessRemote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mountTwigComponent('Ui:Sheet', ['id' => 'x']);
    }

    public function testTheDetailHasAKeyboardResizeGripAndFiltersHaveNone(): void
    {
        $detail = $this->render();
        $grip = $detail->filter('[role="separator"]');

        self::assertSame('true', $detail->filter('dialog')->attr('data-sheet-resizable-value'));
        self::assertCount(1, $grip);
        self::assertSame('vertical', $grip->attr('aria-orientation'));
        self::assertSame('0', $grip->attr('tabindex'));
        self::assertSame('420', $grip->attr('aria-valuemin'));
        self::assertSame('Změnit šířku panelu', $grip->attr('aria-label'));
        self::assertSame('pointerdown->sheet#grab keydown->sheet#key', $grip->attr('data-action'));

        $filters = $this->render(['size' => 'sm']);
        self::assertSame('false', $filters->filter('dialog')->attr('data-sheet-resizable-value'));
        self::assertCount(0, $filters->filter('[role="separator"]'));
    }

    public function testEscapeTheXAndTheScrimCloseItAndAChangedFormAsksFirst(): void
    {
        $crawler = $this->render(['fields' => true]);
        $sheet = $crawler->filter('dialog');
        $actions = (string) $sheet->attr('data-action');

        foreach (['cancel->dialog#cancelled', 'close->dialog#closed', 'click->dialog#clicked', 'popstate@window->dialog#popped', 'dialog:opened->sheet#opened'] as $action) {
            self::assertStringContainsString($action, $actions);
        }
        self::assertSame('true', $sheet->attr('data-dialog-dismissable-value'));
        self::assertSame('dialog#dismiss', $crawler->filter('header button[aria-label="Zavřít"]')->attr('data-action'));
        self::assertNotNull($crawler->filter('footer [data-dialog-target="discard"]')->attr('hidden'));
        self::assertSame('Close', $crawler->filter('footer [data-dialog-target="actions"]')->text());
    }

    public function testTheRemoteHostIsEmptyAndFollowsSheetLinks(): void
    {
        $html = (string) $this->renderTwigComponent('Ui:Sheet', ['id' => 'sheet', 'remote' => true]);
        $sheet = new Crawler($html)->filter('dialog#sheet');

        self::assertSame('true', $sheet->attr('data-dialog-remote-value'));
        self::assertStringContainsString('click@document->dialog#follow', (string) $sheet->attr('data-action'));
        self::assertNull($sheet->attr('aria-labelledby'));
        self::assertCount(0, $sheet->filter('header'));
    }
}
