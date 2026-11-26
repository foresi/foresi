<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Ui\Component;

use Foresi\Ui\Component\PersonPicker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The picker's own logic, without a kernel.
 */
final class PersonPickerLogicTest extends TestCase
{
    /**
     * @param array<mixed> $people
     */
    private function picker(array $people = [], ?string $noneLabel = 'Nobody', string $value = '', string $id = 'r', string $name = 'responsible', string $label = 'Responsible'): PersonPicker
    {
        $picker = new PersonPicker();
        $picker->id = $id;
        $picker->name = $name;
        $picker->label = $label;
        $picker->people = $people;
        $picker->noneLabel = $noneLabel;
        $picker->value = $value;
        $picker->validate();

        return $picker;
    }

    public function testRowsKeepTheSeedOrNullAndNobodyComesFirst(): void
    {
        $picker = $this->picker([['value' => 'm-1', 'name' => 'Jana', 'seed' => 'u-1', 'user' => 'u-1'], ['value' => 'm-2', 'name' => 'Petr']]);

        self::assertSame([
            ['value' => '', 'name' => '', 'seed' => null, 'user' => null],
            ['value' => 'm-1', 'name' => 'Jana', 'seed' => 'u-1', 'user' => 'u-1'],
            ['value' => 'm-2', 'name' => 'Petr', 'seed' => null, 'user' => null],
        ], $picker->rows());
        self::assertSame(['' => 'Nobody', 'm-1' => 'Jana', 'm-2' => 'Petr'], $picker->options());
    }

    public function testWithoutNobodyTheOptionsAreThePeopleOnly(): void
    {
        $picker = $this->picker([['value' => 'm-1', 'name' => 'Jana']], null);

        self::assertSame(['m-1' => 'Jana'], $picker->options());
        self::assertSame([['value' => 'm-1', 'name' => 'Jana', 'seed' => null, 'user' => null]], $picker->rows());
    }

    public function testTheChosenPersonIsFoundByValue(): void
    {
        self::assertSame(['value' => 'm-1', 'name' => 'Jana', 'seed' => 'u-1', 'user' => null], $this->picker([['value' => 'm-1', 'name' => 'Jana', 'seed' => 'u-1']], 'Nobody', 'm-1')->chosen());
        self::assertNull($this->picker([['value' => 'm-1', 'name' => 'Jana']], 'Nobody', '')->chosen());
        self::assertNull($this->picker([['value' => 'm-1', 'name' => 'Jana']], 'Nobody', 'm-9')->chosen());
    }

    public function testTheTriggerLooksLikeAField(): void
    {
        self::assertSame('flex h-control w-full cursor-pointer items-center gap-2 text-left hover:border-green w-full rounded-control border bg-white px-3 text-ink placeholder:text-muted transition-colors border-control-line disabled:border-divider disabled:bg-head disabled:text-muted focus-visible:outline-2 focus-visible:-outline-offset-1 focus-visible:outline-green', $this->picker()->triggerClasses());
    }

    public function testACompactTriggerReadsAsTheValue(): void
    {
        $picker = $this->picker();
        $picker->compact = true;

        self::assertSame(\Foresi\Ui\Component\InlineEdit::TRIGGER, $picker->triggerClasses());
    }

    /**
     * @return iterable<string, array{array<mixed>, string, string, string}>
     */
    public static function invalid(): iterable
    {
        yield 'no id' => [[], '', 'responsible', 'Responsible'];
        yield 'no name' => [[], 'r', '', 'Responsible'];
        yield 'blank label' => [[], 'r', 'responsible', '  '];
        yield 'not an array' => [['nobody'], 'r', 'responsible', 'Responsible'];
        yield 'no value' => [[['name' => 'Jana']], 'r', 'responsible', 'Responsible'];
        yield 'empty value' => [[['value' => '', 'name' => 'Jana']], 'r', 'responsible', 'Responsible'];
        yield 'no person name' => [[['value' => 'm-1']], 'r', 'responsible', 'Responsible'];
        yield 'seed not a string' => [[['value' => 'm-1', 'name' => 'Jana', 'seed' => 5]], 'r', 'responsible', 'Responsible'];
        yield 'user not a string' => [[['value' => 'm-1', 'name' => 'Jana', 'user' => 5]], 'r', 'responsible', 'Responsible'];
    }

    /**
     * @param array<mixed> $people
     */
    #[DataProvider('invalid')]
    public function testInvalidInputThrows(array $people, string $id, string $name, string $label): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->picker($people, 'Nobody', '', $id, $name, $label);
    }
}
