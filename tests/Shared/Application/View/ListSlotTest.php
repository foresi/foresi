<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\ListSlot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The tables of one page keep their state apart in its address: the first on the bare
 * keys, so an old link keeps working, every other under its slot.
 */
final class ListSlotTest extends TestCase
{
    private const array PAGE = ['view' => 'mine', 'cols' => 'name,key', 'reasons' => ['cols' => 'name', 'widths' => 'name:240']];

    public function testTheFirstTableReadsTheBareKeysAndAnotherItsSlot(): void
    {
        self::assertSame(['view' => 'mine', 'cols' => 'name,key'], ListSlot::first(self::PAGE, 'reasons')->query());
        self::assertSame(['cols' => 'name', 'widths' => 'name:240'], ListSlot::named('reasons', self::PAGE)->query());
        self::assertSame([], ListSlot::named('reasons', ['reasons' => 'not a slot'])->query());
    }

    public function testChangingOneTableKeepsTheOther(): void
    {
        self::assertSame(['density' => 'dense', 'reasons' => self::PAGE['reasons']], ListSlot::first(self::PAGE, 'reasons')->with(['density' => 'dense']));
        self::assertSame(['view' => 'mine', 'cols' => 'name,key', 'reasons' => ['density' => '', 'widths' => '']], ListSlot::named('reasons', self::PAGE)->with(['density' => '', 'widths' => '', 'view' => null]));
        self::assertSame(['view' => 'mine', 'cols' => 'name,key'], ListSlot::named('reasons', self::PAGE)->with([]));
    }

    public function testTheFormsOfATableNameItsFieldsAndCarryTheOthers(): void
    {
        $reasons = ListSlot::named('reasons', self::PAGE);
        self::assertSame('reasons[q]', $reasons->field('q'));
        self::assertSame('reasons[c][0][op]', $reasons->field('c[0][op]'));
        self::assertSame('reasons[cols][]', $reasons->field('cols[]'));
        self::assertSame('view-save-as-reasons', $reasons->id('view-save-as'));
        self::assertSame(['view' => 'mine', 'cols' => 'name,key'], $reasons->kept());

        $first = ListSlot::first(self::PAGE, 'reasons');
        self::assertSame('c[0][op]', $first->field('c[0][op]'));
        self::assertSame('view-save-as', $first->id('view-save-as'));
        self::assertSame(['reasons[cols]' => 'name', 'reasons[widths]' => 'name:240'], $first->kept());
    }

    public function testTheInputHoldsTheNamedKeysOfTheTable(): void
    {
        self::assertSame(['view' => 'mine'], ListSlot::first(['view' => 'mine', 0 => 'stray'])->input()->all());
        self::assertSame('name', ListSlot::named('reasons', self::PAGE)->input()->getString('cols'));
    }

    public function testEveryOtherTableIsKeptWhole(): void
    {
        $page = ['cols' => 'name', 'reasons' => ['cols' => 'name', 'n' => 5], 'lines' => ['q' => 'roof']];
        self::assertSame(['reasons[cols]' => 'name', 'reasons[n]' => '5', 'lines[q]' => 'roof'], ListSlot::first($page, 'reasons', 'lines')->kept());
        self::assertSame(['cols' => 'name', 'lines[q]' => 'roof'], ListSlot::named('reasons', $page)->kept());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        yield 'a key of a list' => ['cols'];
        yield 'a digit first' => ['1reasons'];
        yield 'a dash after' => ['reasons-x'];
        yield 'empty' => [''];
    }

    #[DataProvider('badNames')]
    public function testASlotIsAShortNameThatIsNoKeyOfAListsState(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ListSlot::named($name, []);
    }

    public function testTheFirstTableChecksTheNamesOfTheOthers(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ListSlot::first([], 'reasons', 'q');
    }
}
