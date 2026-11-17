<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class PersonPickerTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array PEOPLE = [
        ['value' => 'm-1', 'name' => 'Example Company', 'seed' => 'user-1'],
        ['value' => 'm-2', 'name' => 'Jana Example', 'seed' => 'user-2'],
    ];

    /**
     * @param array<string, mixed> $props
     */
    private function picker(array $props = []): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:PersonPicker', $props + [
            'name' => 'responsible',
            'id' => 'task-responsible',
            'label' => 'Responsible',
            'people' => self::PEOPLE,
            'noneLabel' => 'Nobody',
        ]));
    }

    public function testTheTriggerShowsTheAvatarAndTheNameOfTheChosenPerson(): void
    {
        $trigger = $this->picker(['value' => 'm-2'])->filter('[data-person-picker-trigger]');

        self::assertSame('task-responsible-button-label task-responsible-button', $trigger->attr('aria-labelledby'));
        self::assertSame('menu', $trigger->attr('aria-haspopup'));
        self::assertSame('JE', trim($trigger->filter('[data-avatar]')->text()));
        self::assertStringContainsString('Jana Example', $trigger->text());
    }

    public function testWithNobodyChosenTheTriggerShowsThePlaceholder(): void
    {
        $trigger = $this->picker(['value' => ''])->filter('[data-person-picker-trigger]');

        self::assertStringContainsString('border-dashed', (string) $trigger->filter('[data-avatar]')->attr('class'));
        self::assertStringContainsString('Nobody', $trigger->text());
    }

    public function testEachRowSubmitsItsPersonAndTheChosenOneIsChecked(): void
    {
        $rows = $this->picker(['value' => 'm-1'])->filter('[role=menu] button');

        self::assertSame(['', 'm-1', 'm-2'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('value')));
        self::assertSame(['submit'], array_values(array_unique($rows->each(static fn (Crawler $row): string => (string) $row->attr('type')))));
        self::assertSame(['responsible'], array_values(array_unique($rows->each(static fn (Crawler $row): string => (string) $row->attr('name')))));
        self::assertSame(['false', 'true', 'false'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('aria-checked')));
        self::assertSame(['Nobody', 'Example Company', 'Jana Example'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.truncate')->text())));
    }

    public function testWithoutANoneLabelSomeoneMustBeChosen(): void
    {
        $rows = $this->picker(['noneLabel' => null, 'value' => 'm-1'])->filter('[role=menu] button');

        self::assertSame(['m-1', 'm-2'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('value')));
    }

    public function testWithoutJavaScriptASelectAndASaveButtonTakeItsPlace(): void
    {
        $crawler = $this->picker(['value' => 'm-2', 'saveLabel' => 'Uložit']);

        self::assertSame(['Nobody', 'Example Company', 'Jana Example'], $crawler->filter('noscript #task-responsible option')->each(static fn (Crawler $option): string => trim($option->text())));
        self::assertSame('m-2', $crawler->filter('#task-responsible option[selected]')->attr('value'));
        self::assertSame('Uložit', trim($crawler->filter('noscript button[type=submit]')->text()));
        self::assertStringContainsString('[@media(scripting:none)]:hidden', (string) $crawler->filter('[data-person-picker] > div')->attr('class'));
    }

    #[\PHPUnit\Framework\Attributes\DoesNotPerformAssertions]
    public function testAPersonNeedsAValueAndAName(): void
    {
        foreach ([[['name' => 'Jana']], [['value' => '', 'name' => 'Jana']], [['value' => 'm-1']], ['nobody']] as $people) {
            try {
                $this->mountTwigComponent('Ui:PersonPicker', ['name' => 'responsible', 'id' => 'r', 'label' => 'Responsible', 'people' => $people]);
                self::fail('A person without a value or a name was accepted.');
            } catch (\InvalidArgumentException) {
            }
        }
    }
}
