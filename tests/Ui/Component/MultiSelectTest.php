<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Ui\Component;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;

final class MultiSelectTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const array OPTIONS = [
        ['value' => 'l-1', 'label' => 'Design', 'tone' => 'green'],
        ['value' => 'l-2', 'label' => 'Urgent', 'tone' => 'red'],
        ['value' => 'l-3', 'label' => 'Later'],
    ];

    /**
     * @param array<string, mixed> $props
     */
    private function picker(array $props = []): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent('Ui:MultiSelect', $props + [
            'id' => 'task-labels',
            'name' => 'labels[]',
            'label' => 'Labels',
            'trigger' => 'Choose labels',
            'icon' => 'tag',
            'action' => '/tasks/1/labels',
            'href' => '/tasks/1/labels',
            'token' => 't',
            'options' => self::OPTIONS,
            'values' => ['l-2'],
            'searchLabel' => 'Search labels',
            'emptyLabel' => 'No label matches.',
        ]));
    }

    public function testTheSearchFieldIsAComboboxOverAMultiSelectListboxInADialogPopup(): void
    {
        $picker = $this->picker();
        $search = $picker->filter('input[role="combobox"]');

        self::assertSame('dialog', $picker->filter('button[data-multi-select-trigger]')->attr('aria-haspopup'));
        self::assertSame('dialog', $picker->filter('#task-labels-popup')->attr('role'));
        self::assertSame('Labels', $picker->filter('#task-labels-popup')->attr('aria-label'));
        self::assertSame('task-labels-list', $search->attr('aria-controls'));
        self::assertSame('list', $search->attr('aria-autocomplete'));
        self::assertSame('Search labels', $search->attr('aria-label'));
        self::assertNotNull($search->attr('data-dropdown-focus'));
        self::assertSame('true', $picker->filter('#task-labels-list')->attr('aria-multiselectable'));
        self::assertSame(['false', 'true', 'false'], $picker->filter('[role="option"]')->each(static fn (Crawler $option): string => (string) $option->attr('aria-selected')));
        self::assertSame('status', $picker->filter('[data-multi-select-empty]')->attr('role'));
    }

    public function testTheFormPostsTheTickedValuesAsHiddenCheckboxesWithItsToken(): void
    {
        $picker = $this->picker();

        self::assertSame('/tasks/1/labels', $picker->filter('form')->attr('action'));
        self::assertSame('t', $picker->filter('input[name="_token"]')->attr('value'));
        self::assertSame(['l-1', 'l-2', 'l-3'], $picker->filter('input[type="checkbox"][name="labels[]"]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
        self::assertSame(['l-2'], $picker->filter('input[type="checkbox"][checked]')->each(static fn (Crawler $input): string => (string) $input->attr('value')));
    }

    public function testWithoutJavaScriptTheButtonIsALinkToThePageThatDoesTheSame(): void
    {
        self::assertSame('/tasks/1/labels', $this->picker()->filter('a')->attr('href'));
    }

    public function testTheCreateRowAndItsFieldAreThereOnlyWhenAFieldIsGiven(): void
    {
        $picker = $this->picker();
        self::assertCount(0, $picker->filter('[data-multi-select-create]'));
        self::assertCount(0, $picker->filter('input[name="create"]'));

        $picker = $this->picker(['createName' => 'create', 'createLabel' => 'Create label “{name}”']);
        self::assertSame('option', $picker->filter('[data-multi-select-create]')->attr('role'));
        self::assertNotNull($picker->filter('[data-multi-select-create]')->attr('hidden'));
        self::assertCount(1, $picker->filter('input[type="hidden"][name="create"]'));
        self::assertSame('Create label “{name}”', $picker->filter('form')->attr('data-multi-select-create-label-value'));
    }

    public function testACreateRowNeedsATextWithTheName(): void
    {
        $this->expectException(\Throwable::class);
        $this->picker(['createName' => 'create', 'createLabel' => 'Create']);
    }

    public function testAnOptionNeedsAValueAndALabel(): void
    {
        $this->expectException(\Throwable::class);
        $this->picker(['options' => [['value' => '', 'label' => 'Design']]]);
    }
}
