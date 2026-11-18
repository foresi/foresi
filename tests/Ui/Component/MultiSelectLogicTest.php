<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\MultiSelect;
use Foresi\Ui\Component\Style;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The multi-select's own logic, without a kernel.
 */
final class MultiSelectLogicTest extends TestCase
{
    /**
     * @param array<string, mixed> $props
     */
    private function picker(array $props = []): MultiSelect
    {
        $props += [
            'id' => 'labels', 'name' => 'labels[]', 'label' => 'Labels', 'trigger' => 'Choose labels', 'icon' => 'tag',
            'action' => '/tasks/1/labels', 'href' => '/tasks/1/labels', 'searchLabel' => 'Search labels', 'token' => null, 'fields' => [], 'values' => [],
            'options' => [['value' => 'l-1', 'label' => 'Design', 'tone' => 'green'], ['value' => 'l-2', 'label' => 'Later']],
            'createName' => null, 'createLabel' => '',
        ];
        $picker = new MultiSelect();
        $picker->id = $this->text($props['id']);
        $picker->name = $this->text($props['name']);
        $picker->label = $this->text($props['label']);
        $picker->trigger = $this->text($props['trigger']);
        $picker->icon = \is_string($props['icon']) ? $props['icon'] : null;
        $picker->action = $this->text($props['action']);
        $picker->href = $this->text($props['href']);
        $picker->searchLabel = $this->text($props['searchLabel']);
        $picker->token = \is_string($props['token']) ? $props['token'] : null;
        $picker->fields = $this->items($props['fields']);
        $picker->values = $this->items($props['values']);
        $picker->options = $this->items($props['options']);
        $picker->createName = \is_string($props['createName']) ? $props['createName'] : null;
        $picker->createLabel = $this->text($props['createLabel']);
        $picker->validate();

        return $picker;
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }

    /**
     * @return array<mixed>
     */
    private function items(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function refused(): iterable
    {
        foreach (['id', 'name', 'action', 'href', 'label', 'trigger', 'searchLabel'] as $prop) {
            yield 'no '.$prop => [[$prop => '']];
        }
        foreach (['label', 'trigger', 'searchLabel'] as $prop) {
            yield 'a blank '.$prop => [[$prop => '  ']];
        }
        yield 'an unsafe action' => [['action' => '//example.com/x']];
        yield 'an unsafe page' => [['href' => '//example.com/x']];
        yield 'an icon outside the set' => [['icon' => 'no-such-icon']];
        yield 'a hidden field that is not a text' => [['fields' => ['return' => 5]]];
        yield 'a create row without the name' => [['createName' => 'create', 'createLabel' => 'Create']];
        yield 'an option without a value' => [['options' => [['value' => '', 'label' => 'Design']]]];
        yield 'an option with an empty label' => [['options' => [['value' => 'l-1', 'label' => '']]]];
        yield 'an option with a label that is not a text' => [['options' => [['value' => 'l-1', 'label' => 5]]]];
        yield 'an option with a tone that is not a text' => [['options' => [['value' => 'l-1', 'label' => 'Design', 'tone' => 5]]]];
        yield 'an option with an unknown tone' => [['options' => [['value' => 'l-1', 'label' => 'Design', 'tone' => 'purple']]]];
    }

    /**
     * @param array<string, mixed> $props
     */
    #[DataProvider('refused')]
    public function testItRefuses(array $props): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->picker($props);
    }

    public function testRowsKeepTheToneOrNoneAndTickTheValuesGiven(): void
    {
        self::assertSame([
            ['value' => 'l-1', 'label' => 'Design', 'tone' => 'green', 'checked' => false],
            ['value' => 'l-2', 'label' => 'Later', 'tone' => null, 'checked' => true],
        ], $this->picker(['values' => ['l-2']])->rows());
    }

    public function testTheHiddenFieldsComeAfterTheToken(): void
    {
        self::assertSame(['_token' => 't', 'return' => '/projects', 'view' => 'list'], $this->picker(['token' => 't', 'fields' => ['return' => '/projects', 'view' => 'list']])->hidden());
        self::assertSame(['return' => '/projects'], $this->picker(['fields' => ['return' => '/projects']])->hidden());
    }

    public function testTheSearchFieldIsASmallFieldWithRoomForItsIcon(): void
    {
        self::assertSame('h-control-sm pl-9 text-small '.Style::field(false), $this->picker()->searchClasses());
    }
}
