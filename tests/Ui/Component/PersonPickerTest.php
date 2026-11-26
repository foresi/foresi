<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Contract\PersonPhotos;
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

        // Named by its own text, the label first, so the name holds what it shows (no aria-label).
        self::assertNull($trigger->attr('aria-labelledby'));
        self::assertSame('Responsible: Jana Example', preg_replace('/\s+/', ' ', trim($trigger->filter('.sr-only')->text().' '.$trigger->filter('.truncate')->text())));
        self::assertSame('menu', $trigger->attr('aria-haspopup'));
        self::assertSame('JE', trim($trigger->filter('[data-avatar]')->text()));
        self::assertStringContainsString('Jana Example', $trigger->text());
    }

    public function testAUserWithAPhotoIsShownByItOnTheTriggerAndInTheMenu(): void
    {
        self::getContainer()->set(PersonPhotos::class, new class implements PersonPhotos {
            public function url(string $userId): ?string
            {
                return 'user-1' === $userId ? '/people/user-1/photo?v=1' : null;
            }
        });
        $picker = $this->picker(['value' => 'm-1', 'people' => [
            ['value' => 'm-1', 'name' => 'Example Company', 'seed' => 'user-1', 'user' => 'user-1'],
            ['value' => 'm-2', 'name' => 'Jana Example', 'seed' => 'user-2', 'user' => 'user-2'],
        ]]);

        self::assertSame('/people/user-1/photo?v=1', $picker->filter('[data-person-picker-trigger] img[data-avatar-photo]')->attr('src'));
        self::assertCount(1, $picker->filter('[data-person-picker-option="m-1"] img[data-avatar-photo]'));
        self::assertCount(0, $picker->filter('[data-person-picker-option="m-2"] img'));
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

    public function testACompactPickerReadsAsTheAvatarAndTheNameWithoutALabelAbove(): void
    {
        $crawler = $this->picker(['value' => 'm-2', 'compact' => true]);
        $trigger = $crawler->filter('[data-person-picker-trigger]');

        self::assertSame('Responsible: Jana Example', preg_replace('/\s+/', ' ', trim($trigger->filter('.sr-only')->text().' '.$trigger->filter('.truncate')->text())));
        self::assertCount(0, $trigger->filter('svg'));
        self::assertCount(0, $crawler->filter('[data-person-picker] > div > span[aria-hidden=true]'));
        // Without JavaScript the person reads as plain text; no select in a row.
        self::assertCount(0, $crawler->filter('noscript'));
        self::assertSame('Jana Example', trim($crawler->filter('[data-person-picker] > span .truncate')->text()));
    }

    public function testACompactPickerWithNobodyShowsTheDashedAvatarAlone(): void
    {
        $crawler = $this->picker(['value' => '', 'compact' => true]);
        $trigger = $crawler->filter('[data-person-picker-trigger]');

        self::assertStringContainsString('border-dashed', (string) $trigger->filter('[data-avatar]')->attr('class'));
        self::assertCount(0, $trigger->filter('.truncate'));
        self::assertSame('Responsible: Nobody', preg_replace('/\s+/', ' ', trim(implode(' ', $trigger->filter('.sr-only')->each(static fn (Crawler $span): string => trim($span->text()))))));
        self::assertSame('Responsible: Nobody', preg_replace('/\s+/', ' ', trim($crawler->filter('[data-person-picker] > span')->text())));
        self::assertSame('Nobody', $crawler->filter('[data-person-picker] > span [data-empty-value]')->text());
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
