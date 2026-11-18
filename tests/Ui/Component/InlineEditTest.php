<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class InlineEditTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array STATUSES = [
        ['value' => 's-1', 'label' => 'Open', 'tone' => 'gray'],
        ['value' => 's-2', 'label' => 'In progress', 'tone' => 'amber'],
    ];

    /**
     * @param array<string, mixed> $props
     */
    private function edit(array $props = []): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:InlineEdit', $props + [
            'id' => 'edit-t1-status',
            'action' => '/tasks/t1/status',
            'name' => 'status',
            'label' => 'Status',
            'value' => 's-2',
            'token' => 'csrf-value',
            'fields' => ['return' => '/projects/p1'],
            'options' => self::STATUSES,
            'tone' => 'amber',
        ]));
    }

    public function testAMenuPostsTheChosenOptionWithTheTokenAndTheWayBack(): void
    {
        $crawler = $this->edit();
        $form = $crawler->filter('form[data-inline-edit=status]');

        self::assertSame('post', $form->attr('method'));
        self::assertSame('/tasks/t1/status', $form->attr('action'));
        self::assertSame(['_token' => 'csrf-value', 'return' => '/projects/p1'], array_combine(
            $form->filter('input[type=hidden]')->each(static fn (Crawler $input): string => (string) $input->attr('name')),
            $form->filter('input[type=hidden]')->each(static fn (Crawler $input): string => (string) $input->attr('value')),
        ));
        $rows = $crawler->filter('[role=menu] button');
        self::assertSame(['s-1', 's-2'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('value')));
        self::assertSame(['status'], array_values(array_unique($rows->each(static fn (Crawler $row): string => (string) $row->attr('name')))));
        self::assertSame(['submit'], array_values(array_unique($rows->each(static fn (Crawler $row): string => (string) $row->attr('type')))));
        self::assertSame(['false', 'true'], $rows->each(static fn (Crawler $row): string => (string) $row->attr('aria-checked')));
        // Choosing closes the menu, which puts the focus back on the trigger.
        self::assertSame(['dropdown#close'], array_values(array_unique($rows->each(static fn (Crawler $row): string => (string) $row->attr('data-action')))));
        self::assertSame(['bg-badge-gray-text', 'bg-badge-amber-text'], $rows->each(static fn (Crawler $row): string => trim(str_replace('size-2 shrink-0 rounded-sm', '', (string) $row->filter('span[aria-hidden]')->attr('class')))));
    }

    public function testTheTriggerReadsAsTheValueAndIsNamedByItsOwnText(): void
    {
        $trigger = $this->edit()->filter('#edit-t1-status-button');

        self::assertSame('menu', $trigger->attr('aria-haspopup'));
        self::assertSame('edit-t1-status-menu', $trigger->attr('aria-controls'));
        self::assertNull($trigger->attr('aria-label'));
        self::assertSame('Status: In progress', preg_replace('/\s+/', ' ', trim($trigger->text())));
        self::assertStringContainsString('bg-badge-amber', (string) $trigger->filter('span')->last()->attr('class'));
    }

    public function testNoValueIsADashAScreenReaderHearsAsTheEmptyLabel(): void
    {
        $trigger = $this->edit(['name' => 'priority', 'value' => '', 'options' => [['value' => 'none', 'label' => 'No priority']], 'tone' => null, 'text' => '', 'emptyLabel' => 'No priority'])->filter('#edit-t1-status-button');

        self::assertSame('—', $trigger->filter('span[aria-hidden=true]')->text());
        self::assertSame('Status: No priority', preg_replace('/\s+/', ' ', trim(implode(' ', $trigger->filter('.sr-only')->each(static fn (Crawler $span): string => trim($span->text()))))));
    }

    public function testACardShowsAnIconForNoValue(): void
    {
        $trigger = $this->edit(['value' => '', 'text' => '', 'tone' => null, 'emptyIcon' => 'calendar', 'emptyLabel' => 'No due date'])->filter('#edit-t1-status-button');

        self::assertCount(1, $trigger->filter('svg'));
        self::assertCount(0, $trigger->filter('span[aria-hidden=true]'));
    }

    public function testADateOpensTheBrowsersPickerAndSavesOnChange(): void
    {
        $crawler = $this->edit(['kind' => 'date', 'name' => 'due', 'value' => '2026-12-24', 'text' => '24. 12. 2026', 'tone' => null, 'options' => []]);

        self::assertSame('inline-date', $crawler->filter('form')->attr('data-controller'));
        self::assertSame('inline-date#open', $crawler->filter('#edit-t1-status-button')->attr('data-action'));
        $input = $crawler->filter('input[type=date]');
        self::assertSame('due', $input->attr('name'));
        self::assertSame('2026-12-24', $input->attr('value'));
        self::assertSame('Status', $input->attr('aria-label'));
        self::assertSame('-1', $input->attr('tabindex'));
        self::assertSame('change->inline-date#save', $input->attr('data-action'));
        self::assertStringStartsWith('sr-only ', (string) $input->attr('class'));
        self::assertCount(0, $crawler->filter('[role=menu]'));
    }

    public function testAPersonIsACompactPersonPicker(): void
    {
        $crawler = $this->edit(['kind' => 'person', 'name' => 'responsible', 'label' => 'Responsible', 'value' => 'm-1', 'tone' => null, 'options' => [], 'people' => [['value' => 'm-1', 'name' => 'Jana Example', 'seed' => 'u-1']], 'noneLabel' => 'Nobody']);

        self::assertCount(1, $crawler->filter('form [data-person-picker=responsible]'));
        self::assertSame(['', 'm-1'], $crawler->filter('[role=menu] button')->each(static fn (Crawler $row): string => (string) $row->attr('value')));
    }

    public function testWithoutJavaScriptTheValueReadsAsPlainText(): void
    {
        $crawler = $this->edit();

        self::assertStringContainsString('[@media(scripting:none)]:hidden', (string) $crawler->filter('form > div')->attr('class'));
        $plain = $crawler->filter('form > span');
        self::assertStringContainsString('hidden', (string) $plain->attr('class'));
        self::assertStringContainsString('[@media(scripting:none)]:flex', (string) $plain->attr('class'));
        self::assertSame('Status: In progress', preg_replace('/\s+/', ' ', trim($plain->text())));
    }
}
