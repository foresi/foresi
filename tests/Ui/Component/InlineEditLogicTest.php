<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\InlineEdit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The inline edit's own logic, without a kernel.
 */
final class InlineEditLogicTest extends TestCase
{
    /**
     * @param (\Closure(InlineEdit): void)|null $change
     */
    private function edit(?\Closure $change = null): InlineEdit
    {
        $edit = new InlineEdit();
        $edit->id = 'e';
        $edit->action = '/tasks/t1/priority';
        $edit->name = 'priority';
        $edit->label = 'Priority';
        $edit->options = [['value' => 'none', 'label' => 'No priority'], ['value' => 'high', 'label' => 'High', 'tone' => 'red']];
        if ($change instanceof \Closure) {
            $change($edit);
        }
        $edit->validate();

        return $edit;
    }

    public function testTheOptionsKeepTheirOrderAndTakeTheDotOfTheirTone(): void
    {
        self::assertSame([
            ['value' => 'none', 'label' => 'No priority', 'dot' => null],
            ['value' => 'high', 'label' => 'High', 'dot' => 'bg-badge-red-text'],
        ], $this->edit()->rows());
    }

    public function testItShowsTheTextGivenElseTheLabelOfTheChosenOption(): void
    {
        self::assertSame('High', $this->edit(static function (InlineEdit $edit): void {
            $edit->value = 'high';
        })->shown());
        self::assertSame('', $this->edit(static function (InlineEdit $edit): void {
            $edit->value = 'low';
        })->shown());
        self::assertSame('Overdue 1. 1.', $this->edit(static function (InlineEdit $edit): void {
            $edit->value = 'high';
            $edit->text = 'Overdue 1. 1.';
        })->shown());
        self::assertSame('', $this->edit(static function (InlineEdit $edit): void {
            $edit->value = 'high';
            $edit->text = '';
        })->shown());
    }

    public function testTheTokenComesFirstAmongTheHiddenFields(): void
    {
        self::assertSame(['_token' => 't', 'return' => '/my-tasks'], $this->edit(static function (InlineEdit $edit): void {
            $edit->token = 't';
            $edit->fields = ['return' => '/my-tasks'];
        })->hidden());
        self::assertSame(['return' => '/my-tasks'], $this->edit(static function (InlineEdit $edit): void {
            $edit->fields = ['return' => '/my-tasks'];
        })->hidden());
    }

    public function testADateOrAPersonNeedsNoOptions(): void
    {
        self::assertSame([], $this->edit(static function (InlineEdit $edit): void {
            $edit->kind = 'date';
            $edit->options = [];
        })->rows());
        self::assertSame([], $this->edit(static function (InlineEdit $edit): void {
            $edit->kind = 'person';
            $edit->options = [];
        })->rows());
    }

    /**
     * @return iterable<string, array{\Closure(InlineEdit): void}>
     */
    public static function invalid(): iterable
    {
        yield 'no id' => [static function (InlineEdit $edit): void { $edit->id = ''; }];
        yield 'no name' => [static function (InlineEdit $edit): void { $edit->name = ''; }];
        yield 'no action' => [static function (InlineEdit $edit): void { $edit->action = ''; }];
        yield 'blank label' => [static function (InlineEdit $edit): void { $edit->label = '  '; }];
        yield 'unknown kind' => [static function (InlineEdit $edit): void { $edit->kind = 'text'; }];
        yield 'unsafe action' => [static function (InlineEdit $edit): void { $edit->action = 'javascript:alert(1)'; }];
        yield 'unknown icon' => [static function (InlineEdit $edit): void { $edit->emptyIcon = 'not-an-icon'; }];
        yield 'unknown tone' => [static function (InlineEdit $edit): void { $edit->tone = 'purple'; }];
        yield 'field name not a string' => [static function (InlineEdit $edit): void { $edit->fields = ['x']; }];
        yield 'field value not a string' => [static function (InlineEdit $edit): void { $edit->fields = ['return' => 5]; }];
        yield 'option not an array' => [static function (InlineEdit $edit): void { $edit->options = ['high']; }];
        yield 'option without a value' => [static function (InlineEdit $edit): void { $edit->options = [['label' => 'High']]; }];
        yield 'option without a label' => [static function (InlineEdit $edit): void { $edit->options = [['value' => 'high']]; }];
        yield 'option with an empty label' => [static function (InlineEdit $edit): void { $edit->options = [['value' => 'high', 'label' => '']]; }];
        yield 'option of an unknown tone' => [static function (InlineEdit $edit): void { $edit->options = [['value' => 'high', 'label' => 'High', 'tone' => 'purple']]; }];
        yield 'menu without options' => [static function (InlineEdit $edit): void { $edit->options = []; }];
    }

    /**
     * @param \Closure(InlineEdit): void $change
     */
    #[DataProvider('invalid')]
    public function testInvalidInputThrows(\Closure $change): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->edit($change);
    }
}
