<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class ProgressBarTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    public function testShowsTheShareDoneAsAnAccessibleProgressBar(): void
    {
        $crawler = $this->renderTwigComponent('Ui:ProgressBar', ['label' => 'Importing rows', 'value' => 1500, 'max' => 3000, 'valueText' => '1,500 of 3,000 rows'])->crawler();

        $bar = $crawler->filter('[role="progressbar"]');
        self::assertSame('Importing rows', $bar->attr('aria-label'));
        self::assertSame('0', $bar->attr('aria-valuemin'));
        self::assertSame('3000', $bar->attr('aria-valuemax'));
        self::assertSame('1500', $bar->attr('aria-valuenow'));
        self::assertSame('1,500 of 3,000 rows', $bar->attr('aria-valuetext'));
        self::assertSame('width:50%', $crawler->filter('[role="progressbar"] > div')->attr('style'));
        self::assertStringContainsString('1,500 of 3,000 rows', $crawler->filter('.num')->text());
    }

    public function testWithoutAMaximumItOnlySaysItIsBusy(): void
    {
        $crawler = $this->renderTwigComponent('Ui:ProgressBar', ['label' => 'Reading the file'])->crawler();

        $bar = $crawler->filter('[role="progressbar"]');
        self::assertNull($bar->attr('aria-valuenow'));
        self::assertNull($bar->attr('aria-valuemax'));
        self::assertStringContainsString('motion-safe:animate-pulse', $bar->html());
        self::assertCount(0, $crawler->filter('.num'));
    }

    public function testNothingToDoIsDone(): void
    {
        $crawler = $this->renderTwigComponent('Ui:ProgressBar', ['label' => 'Done', 'value' => 0, 'max' => 0])->crawler();

        self::assertSame('width:100%', $crawler->filter('[role="progressbar"] > div')->attr('style'));
    }

    public function testRejectsAValueAboveTheMaximumAndAMissingLabel(): void
    {
        foreach ([['label' => 'Rows', 'value' => 5, 'max' => 4], ['label' => 'Rows', 'value' => -1, 'max' => 4], ['label' => '']] as $props) {
            try {
                $this->renderTwigComponent('Ui:ProgressBar', $props);
                self::fail('The progress bar should refuse these props.');
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('should refuse', $error->getMessage());
            }
        }
    }
}
