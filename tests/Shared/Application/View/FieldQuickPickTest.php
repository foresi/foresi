<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Shared\Application\View;

use Foresi\Shared\Application\View\Field;
use Foresi\Shared\Application\View\FieldType;
use Foresi\Shared\Application\View\QuickPick;
use PHPUnit\Framework\TestCase;

/**
 * How a field's quick filter gets its values and which operator it sets.
 */
final class FieldQuickPickTest extends TestCase
{
    public function testAFieldTakesThePickOfItsType(): void
    {
        self::assertSame(QuickPick::Values, QuickPick::of(FieldType::Enum));
        self::assertSame(QuickPick::Values, QuickPick::of(FieldType::MultiEnum));
        self::assertSame(QuickPick::Values, QuickPick::of(FieldType::Boolean));
        self::assertSame(QuickPick::Months, QuickPick::of(FieldType::Date));
        self::assertSame(QuickPick::Lookup, QuickPick::of(FieldType::Reference));
        self::assertSame(QuickPick::Sheet, QuickPick::of(FieldType::Text));
        self::assertSame(QuickPick::Sheet, QuickPick::of(FieldType::Number));

        self::assertSame(QuickPick::Values, (new Field('paid', FieldType::Boolean, 'Paid'))->quickPick);
        self::assertSame(QuickPick::Lookup, (new Field('customer', FieldType::Reference, 'Customer', source: 'contact'))->quickPick);
        self::assertSame(QuickPick::Sheet, (new Field('customer', FieldType::Reference, 'Customer'))->quickPick, 'A reference without a picker of its source is picked in the sheet.');
    }

    public function testAFieldMayDeclareAnotherPick(): void
    {
        self::assertSame(QuickPick::Sheet, (new Field('kind', FieldType::Enum, 'Kind', options: ['a', 'b'], quick: QuickPick::Sheet))->quickPick);
        self::assertSame(QuickPick::Lookup, (new Field('name', FieldType::Text, 'Name', source: 'contact', quick: QuickPick::Lookup))->quickPick);
    }

    public function testOnlyAFieldWithASourceLooksUpItsRecords(): void
    {
        self::assertSame(QuickPick::Sheet, (new Field('name', FieldType::Text, 'Name', source: 'contact'))->quickPick, 'A source alone does not make a lookup.');

        $this->expectExceptionMessageIsOrContains('The field "name" looks up the records of its source, which it names none of.');

        new Field('name', FieldType::Text, 'Name', quick: QuickPick::Lookup);
    }

    public function testAQuickFilterPicksSeveralWhereTheFieldTakesAnyOf(): void
    {
        $enum = new Field('kind', FieldType::Enum, 'Kind', options: ['a', 'b']);
        $single = new Field('kind', FieldType::Enum, 'Kind', ['is', 'is_not'], options: ['a', 'b']);
        $set = new Field('roles', FieldType::MultiEnum, 'Roles', options: ['a', 'b']);
        $setWithoutAny = new Field('roles', FieldType::MultiEnum, 'Roles', ['has_none_of'], options: ['a', 'b']);
        $text = new Field('name', FieldType::Text, 'Name');

        self::assertTrue($enum->multiPick());
        self::assertSame('is_any_of', $enum->pickOperator());
        self::assertFalse($single->multiPick());
        self::assertSame('is', $single->pickOperator());
        self::assertTrue($set->multiPick());
        self::assertSame('has_any_of', $set->pickOperator());
        self::assertFalse($setWithoutAny->multiPick());
        self::assertSame('has_any_of', $setWithoutAny->pickOperator(), 'A set is picked by "has any of" alone.');
        self::assertFalse($text->multiPick());
        self::assertSame('is', $text->pickOperator());
    }
}
