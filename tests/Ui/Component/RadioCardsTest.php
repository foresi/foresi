<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class RadioCardsTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    /**
     * @return list<array<string, string>>
     */
    private function options(): array
    {
        return [
            ['value' => 'add', 'label' => 'Add missing only', 'text' => 'Existing records stay as they are.'],
            ['value' => 'update', 'label' => 'Update existing', 'text' => 'Matched records take the values of the file.'],
            ['value' => 'replace', 'label' => 'Full replace', 'text' => 'The records match the file.', 'warning' => 'Records missing from the file are archived.'],
        ];
    }

    public function testRendersOneLabelledRadioPerOptionInAFieldset(): void
    {
        $crawler = $this->renderTwigComponent('Ui:RadioCards', ['name' => 'mode', 'id' => 'mode', 'legend' => 'Mode', 'options' => $this->options(), 'value' => 'update'])->crawler();

        self::assertSame('Mode', $crawler->filter('fieldset > legend')->text());
        self::assertCount(3, $crawler->filter('input[type="radio"][name="mode"]'));
        self::assertSame('update', $crawler->filter('input:checked')->attr('value'));
        self::assertSame('mode-replace', $crawler->filter('label')->eq(2)->attr('for'));
        self::assertStringContainsString('Matched records take the values of the file.', $crawler->filter('label')->eq(1)->text());
        self::assertStringContainsString('has-[:focus-visible]:outline-2', (string) $crawler->filter('label')->eq(0)->attr('class'));
    }

    public function testAWarningIsShownOnlyOnItsOption(): void
    {
        $crawler = $this->renderTwigComponent('Ui:RadioCards', ['name' => 'mode', 'id' => 'mode', 'legend' => 'Mode', 'options' => $this->options()])->crawler();

        self::assertCount(1, $crawler->filter('.text-badge-amber-text'));
        self::assertStringContainsString('Records missing from the file are archived.', $crawler->filter('label')->eq(2)->text());
        self::assertCount(0, $crawler->filter('input:checked'));
    }

    public function testAnErrorIsLinkedToTheFieldset(): void
    {
        $crawler = $this->renderTwigComponent('Ui:RadioCards', ['name' => 'mode', 'id' => 'mode', 'legend' => 'Mode', 'options' => $this->options(), 'error' => 'Choose a mode.'])->crawler();

        self::assertSame('mode-error', $crawler->filter('fieldset')->attr('aria-describedby'));
        self::assertSame('Choose a mode.', $crawler->filter('#mode-error')->text());
        self::assertCount(3, $crawler->filter('input[aria-invalid="true"]'));
    }

    public function testRejectsTooFewOptionsAndOptionsWithoutText(): void
    {
        foreach ([[$this->options()[0]], [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B', 'text' => 'B']]] as $options) {
            try {
                $this->renderTwigComponent('Ui:RadioCards', ['name' => 'mode', 'id' => 'mode', 'legend' => 'Mode', 'options' => $options]);
                self::fail('Radio cards should refuse these options.');
            } catch (\Throwable $error) {
                self::assertStringNotContainsString('should refuse', $error->getMessage());
            }
        }
    }

    public function testTheFirstOptionOfAnInvalidGroupTakesTheFocus(): void
    {
        $crawler = $this->renderTwigComponent('Ui:RadioCards', ['name' => 'mode', 'id' => 'mode', 'legend' => 'Mode', 'options' => $this->options(), 'error' => 'Choose a mode.'])->crawler();

        $radios = $crawler->filter('input[type="radio"]');
        self::assertNotNull($radios->eq(0)->attr('autofocus'));
        self::assertCount(1, $crawler->filter('[autofocus]'));
        self::assertSame('mode-error', $radios->eq(1)->attr('aria-describedby'));

        $valid = $this->renderTwigComponent('Ui:RadioCards', ['name' => 'other', 'id' => 'other', 'legend' => 'Other', 'options' => $this->options()])->crawler();
        self::assertCount(0, $valid->filter('[autofocus], [aria-describedby]'));
    }
}
