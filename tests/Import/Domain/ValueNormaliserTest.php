<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Domain;

use App\Import\Domain\DateOrder;
use App\Import\Domain\DateParser;
use App\Import\Domain\DecimalSeparator;
use App\Import\Domain\FieldDefinition;
use App\Import\Domain\FieldType;
use App\Import\Domain\NormalisationOptions;
use App\Import\Domain\RowError;
use App\Import\Domain\Text;
use App\Import\Domain\ValueNormaliser;
use App\Shared\Domain\Countries;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ValueNormaliser::class)]
#[CoversClass(NormalisationOptions::class)]
#[CoversClass(DateParser::class)]
#[CoversClass(Text::class)]
#[CoversClass(Countries::class)]
#[CoversClass(FieldDefinition::class)]
final class ValueNormaliserTest extends TestCase
{
    private const string NBSP = "\u{00A0}";

    /**
     * @param array<array-key, mixed> $valueMap
     */
    #[DataProvider('values')]
    public function testReadsValues(FieldType $type, string $raw, mixed $expected, ?NormalisationOptions $options = null, array $valueMap = []): void
    {
        $field = new FieldDefinition('f', 'label', $type, choices: ['Customer', 'Supplier']);

        $actual = new ValueNormaliser()->normalise($field, $raw, $options ?? new NormalisationOptions(), $valueMap);

        if ($expected instanceof \DateTimeImmutable) {
            self::assertInstanceOf(\DateTimeImmutable::class, $actual);
            self::assertSame($expected->format('c'), $actual->format('c'));
            self::assertSame('UTC', $actual->getTimezone()->getName());
        } else {
            self::assertSame($expected, $actual);
        }
    }

    /**
     * @return iterable<string, array{0: FieldType, 1: string, 2: mixed, 3?: ?NormalisationOptions, 4?: array<array-key, mixed>}>
     */
    public static function values(): iterable
    {
        $mdy = new NormalisationOptions(DateOrder::MonthDayYear);
        $ymd = new NormalisationOptions(DateOrder::YearMonthDay);
        $point = new NormalisationOptions(decimalSeparator: DecimalSeparator::Point);

        foreach (FieldType::cases() as $type) {
            yield 'empty '.$type->value => [$type, '', null];
            yield 'blank '.$type->value => [$type, '  '.self::NBSP.' ', null];
        }

        yield 'text trimmed' => [FieldType::Text, '  Example Company ', 'Example Company'];
        yield 'text keeps inner space' => [FieldType::Text, 'Example  Company', 'Example  Company'];

        yield 'email' => [FieldType::Email, ' testing@example.com ', 'testing@example.com'];
        yield 'email list two' => [FieldType::EmailList, 'a@example.com; b@example.com', ['a@example.com', 'b@example.com']];
        yield 'email list mixed separators' => [FieldType::EmailList, "a@example.com, b@example.com\nc@example.com;d@example.com", ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com']];
        yield 'email list one' => [FieldType::EmailList, 'a@example.com', ['a@example.com']];
        yield 'email list duplicates' => [FieldType::EmailList, 'a@example.com,a@example.com', ['a@example.com']];
        yield 'email list only separators' => [FieldType::EmailList, ' ; ', null];

        yield 'phone' => [FieldType::Phone, ' +420  123 456 789 ', '+420 123 456 789'];
        yield 'phone five digits' => [FieldType::Phone, '12345', '12345'];
        yield 'phone twenty digits' => [FieldType::Phone, '12345678901234567890', '12345678901234567890'];
        yield 'phone punctuation' => [FieldType::Phone, '(0420) 123-456/78', '(0420) 123-456/78'];

        yield 'decimal comma with thousands' => [FieldType::Decimal, '1 234,50', '1234.50'];
        yield 'decimal comma with nbsp' => [FieldType::Decimal, '1'.self::NBSP.'234,5', '1234.5'];
        yield 'decimal comma with points as thousands' => [FieldType::Decimal, '1.234.567,89', '1234567.89'];
        yield 'decimal comma whole' => [FieldType::Decimal, '42', '42'];
        yield 'decimal comma negative' => [FieldType::Decimal, '-1 234,5', '-1234.5'];
        yield 'decimal comma plus sign' => [FieldType::Decimal, '+3,5', '3.5'];
        yield 'decimal comma grouping only' => [FieldType::Decimal, '1.234', '1234'];
        yield 'decimal point grouping only' => [FieldType::Decimal, '1,234', '1234', $point];
        yield 'decimal point millions' => [FieldType::Decimal, '1,234,567.89', '1234567.89', $point];
        yield 'decimal comma zero' => [FieldType::Decimal, '0,123', '0.123'];
        yield 'decimal point' => [FieldType::Decimal, '1,234.50', '1234.50', $point];
        yield 'decimal point plain' => [FieldType::Decimal, '1234.5', '1234.5', $point];
        yield 'decimal point with spaces' => [FieldType::Decimal, '1 234.5', '1234.5', $point];

        yield 'integer' => [FieldType::Integer, '42', 42];
        yield 'integer with spaces' => [FieldType::Integer, '1 234', 1234];
        yield 'integer negative' => [FieldType::Integer, '-7', -7];
        yield 'integer plus' => [FieldType::Integer, '+7', 7];
        yield 'integer leading zeros' => [FieldType::Integer, '007', 7];
        yield 'integer zero' => [FieldType::Integer, '0', 0];
        yield 'integer zeros' => [FieldType::Integer, '000', 0];
        yield 'integer negative leading zeros' => [FieldType::Integer, '-007', -7];

        yield 'date dmy' => [FieldType::Date, '01.10.2026', new \DateTimeImmutable('2026-10-01T00:00:00+00:00')];
        yield 'date dmy spaces' => [FieldType::Date, '1. 10. 2026', new \DateTimeImmutable('2026-10-01T00:00:00+00:00')];
        yield 'date dmy slash' => [FieldType::Date, '31/12/2026', new \DateTimeImmutable('2026-12-31T00:00:00+00:00')];
        yield 'date dmy dash' => [FieldType::Date, '5-6-2026', new \DateTimeImmutable('2026-06-05T00:00:00+00:00')];
        yield 'date mdy' => [FieldType::Date, '10/01/2026', new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), $mdy];
        yield 'date ymd' => [FieldType::Date, '2026/10/1', new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), $ymd];
        yield 'date iso under dmy' => [FieldType::Date, '2026-10-01', new \DateTimeImmutable('2026-10-01T00:00:00+00:00')];
        yield 'date iso under mdy' => [FieldType::Date, '2026-10-01', new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), $mdy];
        yield 'date iso datetime' => [FieldType::Date, '2026-10-01T13:45:10', new \DateTimeImmutable('2026-10-01T00:00:00+00:00')];
        yield 'date iso datetime space' => [FieldType::Date, '2026-10-01 23:59', new \DateTimeImmutable('2026-10-01T00:00:00+00:00')];
        yield 'date leap day' => [FieldType::Date, '29.02.2028', new \DateTimeImmutable('2028-02-29T00:00:00+00:00')];

        yield 'boolean ano' => [FieldType::Boolean, 'Ano', true];
        yield 'boolean words true' => [FieldType::Boolean, ' YES ', true];
        yield 'boolean x' => [FieldType::Boolean, 'x', true];
        yield 'boolean 1' => [FieldType::Boolean, '1', true];
        yield 'boolean ne' => [FieldType::Boolean, 'NE', false];
        yield 'boolean false' => [FieldType::Boolean, 'false', false];
        yield 'boolean 0' => [FieldType::Boolean, '0', false];
        yield 'boolean n' => [FieldType::Boolean, 'n', false];
        yield 'boolean mapped true' => [FieldType::Boolean, 'active', true, null, ['active' => true]];
        yield 'boolean mapped false' => [FieldType::Boolean, 'closed', false, null, ['closed' => false]];

        yield 'choice exact' => [FieldType::Choice, 'Customer', 'Customer'];
        yield 'choice case-insensitive' => [FieldType::Choice, 'sUpPlIeR', 'Supplier'];
        yield 'choice mapped' => [FieldType::Choice, 'odberatel', 'Customer', null, ['odberatel' => 'Customer']];
        yield 'choice map wins' => [FieldType::Choice, 'Customer', 'Supplier', null, ['Customer' => 'Supplier']];
        yield 'map keys are trimmed' => [FieldType::Choice, 'odberatel', 'Customer', null, [' odberatel ' => 'Customer']];
        yield 'choice numeric key' => [FieldType::Choice, '1', 'Supplier', null, ['1' => 'Supplier']];

        yield 'country code' => [FieldType::Country, 'cz', 'CZ'];
        yield 'country English' => [FieldType::Country, 'Germany', 'DE'];
        yield 'country Czech' => [FieldType::Country, 'Česká republika', 'CZ'];
        yield 'country Czech no diacritics' => [FieldType::Country, 'ceska republika', 'CZ'];
        yield 'country Czech other' => [FieldType::Country, 'Slovensko', 'SK'];
        yield 'country last in list' => [FieldType::Country, 'United States', 'US'];
        yield 'country Czech official English' => [FieldType::Country, 'Czech Republic', 'CZ'];
        yield 'country USA' => [FieldType::Country, 'usa', 'US'];
        yield 'country mapped' => [FieldType::Country, 'ČR', 'CZ', null, ['ČR' => 'CZ']];
        yield 'country map wins' => [FieldType::Country, 'DE', 'AT', null, ['DE' => 'AT']];

        yield 'identifier' => [FieldType::Identifier, ' cz 1234 5678 ', 'CZ12345678'];
        yield 'identifier nbsp' => [FieldType::Identifier, 'ab'.self::NBSP.'12', 'AB12'];
    }

    /**
     * @param array<array-key, mixed>|null $map
     */
    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(FieldType $type, string $raw, string $messageKey, ?NormalisationOptions $options = null, ?string $badPart = null, ?array $map = null): void
    {
        $field = new FieldDefinition('myField', 'label', $type, choices: ['Customer', 'Supplier']);

        $actual = new ValueNormaliser()->normalise($field, $raw, $options ?? new NormalisationOptions(), $map ?? ['ČR' => 'CZ', 'on' => 'yes', 'word' => 5]);

        self::assertInstanceOf(RowError::class, $actual);
        self::assertSame('myField', $actual->field);
        self::assertSame($messageKey, $actual->messageKey);
        self::assertSame(['value' => $badPart ?? trim($raw)], $actual->parameters);
    }

    /**
     * @return iterable<string, array{0: FieldType, 1: string, 2: string, 3?: ?NormalisationOptions, 4?: ?string, 5?: array<array-key, mixed>}>
     */
    public static function invalidValues(): iterable
    {
        $mdy = new NormalisationOptions(DateOrder::MonthDayYear);
        $ymd = new NormalisationOptions(DateOrder::YearMonthDay);
        $point = new NormalisationOptions(decimalSeparator: DecimalSeparator::Point);

        yield 'email without domain' => [FieldType::Email, 'a@', 'import.error.invalid_email'];
        yield 'email list one bad' => [FieldType::EmailList, 'a@example.com; a@', 'import.error.invalid_email', null, 'a@'];
        yield 'email list reports the bad one' => [FieldType::EmailList, 'nonsense', 'import.error.invalid_email'];
        yield 'phone letters' => [FieldType::Phone, '123 abc 456', 'import.error.invalid_phone'];
        yield 'phone too short' => [FieldType::Phone, '1234', 'import.error.invalid_phone'];
        yield 'phone too long' => [FieldType::Phone, '123456789012345678901', 'import.error.invalid_phone'];
        yield 'decimal wrong separator comma mode' => [FieldType::Decimal, '12.50', 'import.error.invalid_decimal'];
        yield 'decimal wrong separator short' => [FieldType::Decimal, '1.5', 'import.error.invalid_decimal'];
        yield 'decimal leading zero grouping' => [FieldType::Decimal, '0.123', 'import.error.invalid_decimal'];
        yield 'decimal bad grouping' => [FieldType::Decimal, '1.23.456', 'import.error.invalid_decimal'];
        yield 'decimal long first group' => [FieldType::Decimal, '1234.567', 'import.error.invalid_decimal'];
        yield 'decimal grouping then wrong decimals' => [FieldType::Decimal, '1,234.5', 'import.error.invalid_decimal'];
        yield 'decimal wrong separator point mode' => [FieldType::Decimal, '1,5', 'import.error.invalid_decimal', $point];
        yield 'decimal point mode comma decimals' => [FieldType::Decimal, '1.234,50', 'import.error.invalid_decimal', $point];
        yield 'decimal letters' => [FieldType::Decimal, '12a', 'import.error.invalid_decimal'];
        yield 'decimal two separators' => [FieldType::Decimal, '1,2,3', 'import.error.invalid_decimal'];
        yield 'decimal double sign' => [FieldType::Decimal, '--5', 'import.error.invalid_decimal'];
        yield 'decimal trailing separator' => [FieldType::Decimal, '5,', 'import.error.invalid_decimal'];
        yield 'decimal leading separator' => [FieldType::Decimal, ',5', 'import.error.invalid_decimal'];
        yield 'decimal point two points' => [FieldType::Decimal, '1.2.3', 'import.error.invalid_decimal', $point];
        yield 'integer fraction' => [FieldType::Integer, '1,5', 'import.error.invalid_integer'];
        yield 'integer letters' => [FieldType::Integer, 'x1', 'import.error.invalid_integer'];
        yield 'integer overflow' => [FieldType::Integer, '99999999999999999999', 'import.error.invalid_integer'];
        yield 'date not a date' => [FieldType::Date, 'tomorrow', 'import.error.invalid_date'];
        yield 'date month 13 under dmy' => [FieldType::Date, '01.13.2026', 'import.error.invalid_date'];
        yield 'date day 31 in november' => [FieldType::Date, '31.11.2026', 'import.error.invalid_date'];
        yield 'date no leap day' => [FieldType::Date, '29.02.2027', 'import.error.invalid_date'];
        yield 'date two digit year' => [FieldType::Date, '01.10.26', 'import.error.invalid_date'];
        yield 'date day first under mdy' => [FieldType::Date, '31/12/2026', 'import.error.invalid_date', $mdy];
        yield 'date month first under dmy' => [FieldType::Date, '12/31/2026', 'import.error.invalid_date'];
        yield 'date dmy under ymd' => [FieldType::Date, '01.10.2026', 'import.error.invalid_date', $ymd];
        yield 'date iso month 13' => [FieldType::Date, '2026-13-01', 'import.error.invalid_date'];
        yield 'date text around' => [FieldType::Date, 'on 01.10.2026', 'import.error.invalid_date'];
        yield 'date text before iso' => [FieldType::Date, 'x2026-10-01', 'import.error.invalid_date'];
        yield 'date text after iso' => [FieldType::Date, '2026-10-01x', 'import.error.invalid_date'];
        yield 'date three digit year' => [FieldType::Date, '01.10.202', 'import.error.invalid_date'];
        yield 'date five digit year' => [FieldType::Date, '01.10.20266', 'import.error.invalid_date'];
        yield 'date text after' => [FieldType::Date, '01.10.2026 x', 'import.error.invalid_date'];
        yield 'boolean unknown' => [FieldType::Boolean, 'maybe', 'import.error.unknown_value'];
        yield 'boolean mapped to non-boolean' => [FieldType::Boolean, 'word', 'import.error.unknown_value'];
        yield 'boolean mapped to string' => [FieldType::Boolean, 'on', 'import.error.unknown_value'];
        yield 'choice unknown' => [FieldType::Choice, 'Partner', 'import.error.unknown_value'];
        yield 'country mapped to unknown code' => [FieldType::Country, 'ČR', 'import.error.unknown_value', null, null, ['ČR' => 'XX']];
        yield 'choice mapped to disallowed value' => [FieldType::Choice, 'odberatel', 'import.error.unknown_value', null, null, ['odberatel' => 'Partner']];
        yield 'choice mapped to non-string' => [FieldType::Choice, 'odberatel', 'import.error.unknown_value', null, null, ['odberatel' => 5]];
        yield 'country unknown' => [FieldType::Country, 'Atlantis', 'import.error.unknown_value'];
        yield 'country not offered' => [FieldType::Country, 'JP', 'import.error.unknown_value'];
        yield 'country unmapped abbreviation' => [FieldType::Country, 'ČRR', 'import.error.unknown_value'];
    }

    public function testCountriesKnowTheirNames(): void
    {
        self::assertSame('CZ', Countries::find('Czechia'));
        self::assertSame('Czechia', Countries::name('CZ', 'en'));
        self::assertSame('Česko', Countries::name('CZ', 'cs'));
        self::assertTrue(Countries::supports('SK'));
        self::assertFalse(Countries::supports('sk'));
        self::assertNull(Countries::find(''));
        self::assertSame('GB', Countries::find('Velká Británie'));
        self::assertSame('SK', Countries::find('Slovenská republika'));
        self::assertSame('US', Countries::find('Spojené státy'));
    }

    /**
     * @param list<string> $samples
     */
    #[DataProvider('detections')]
    public function testDetectsOptions(array $samples, DateOrder $order, DecimalSeparator $separator, bool $ambiguous, bool $ambiguousDecimal = false): void
    {
        $options = NormalisationOptions::detect($samples);

        self::assertSame($order, $options->dateOrder);
        self::assertSame($separator, $options->decimalSeparator);
        self::assertSame($ambiguous, $options->ambiguousDateOrder);
        self::assertSame($ambiguousDecimal, $options->ambiguousDecimal);
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: DateOrder, 2: DecimalSeparator, 3: bool, 4?: bool}>
     */
    public static function detections(): iterable
    {
        yield 'nothing' => [[], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'blanks' => [['', '  '], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'day first proven' => [['13.10.2026', '01.02.2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'month first proven' => [['10/13/2026', '01/02/2026'], DateOrder::MonthDayYear, DecimalSeparator::Comma, false];
        yield 'ambiguous' => [['01.02.2026', '03.04.2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, true];
        yield 'single ambiguous' => [['01/02/2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, true];
        yield 'iso is not ambiguous' => [['2026-10-01', '2026-02-03'], DateOrder::YearMonthDay, DecimalSeparator::Comma, false];
        yield 'year first with slashes' => [['2026/10/01'], DateOrder::YearMonthDay, DecimalSeparator::Comma, false];
        yield 'iso with day first' => [['2026-10-01', '13.10.2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'iso with month first' => [['2026-10-01', '10/13/2026'], DateOrder::MonthDayYear, DecimalSeparator::Comma, false];
        yield 'neither parses' => [['13.13.2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'one bad sample spoils both' => [['13.10.2026', '10/13/2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'dates do not vote on decimals' => [['01.10.2026', '13.10.2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'comma decimals' => [['1 234,50', '5,5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'point decimals' => [['1,234.50', '5.5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'point decimal alone' => [['12.5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'point wins by majority' => [['1.5', '2.5', '3,5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'comma wins by majority' => [['1,5', '2,5', '3.5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'tie goes to comma' => [['1.5', '2,5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'three digits are ambiguous' => [['1.234', '5.678'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false, true];
        yield 'three digits after comma are ambiguous' => [['1,234'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false, true];
        yield 'ambiguous but decided by another' => [['1,234', '5,5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'leading zero votes for the point' => [['0.123'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'leading zero votes for the comma' => [['0,123', '1.5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'four leading digits are not grouping' => [['1234.567'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'signed ambiguous' => [['-1.234'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false, true];
        yield 'ambiguous with integers' => [['5', '1.234'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false, true];
        yield 'repeated points are thousands' => [['1.234.567'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'repeated commas are thousands' => [['1,234,567', '5.5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'mixed with last comma' => [['1.234,5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'padded dates' => [[' 13.10.2026 ', '', ' 01.02.2026'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'padded month first' => [[' 10/13/2026 '], DateOrder::MonthDayYear, DecimalSeparator::Comma, false];
        yield 'last comma beats earlier point' => [['1.234,5', '1.234,6', '2.5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'last point beats earlier comma' => [['1,234.5', '1,234.6', '2,5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'one decimal digit' => [['1.5', '2.5', '3,12'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'four decimal digits' => [['1.2345', '2.5', '3,12'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'four comma digits' => [['1,2345', '2,5', '3.12'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'negative number' => [['-1.5', '-2.5', '3,5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
        yield 'text is ignored' => [['hello', 'x.5'], DateOrder::DayMonthYear, DecimalSeparator::Comma, false];
        yield 'spaces inside numbers' => [['1 234.5'], DateOrder::DayMonthYear, DecimalSeparator::Point, false];
    }

    public function testOptionsDefaults(): void
    {
        $options = new NormalisationOptions();

        self::assertSame(DateOrder::DayMonthYear, $options->dateOrder);
        self::assertSame(DecimalSeparator::Comma, $options->decimalSeparator);
        self::assertFalse($options->ambiguousDateOrder);
    }

    public function testFieldDefinitionDefaults(): void
    {
        $field = new FieldDefinition('name', 'label.name', FieldType::Text);

        self::assertFalse($field->required);
        self::assertSame([], $field->aliases);
        self::assertSame([], $field->choices);
        self::assertSame('', $field->group);
    }

    public function testDatesAreNotShiftedByTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Auckland');
        try {
            $date = DateParser::parse('01.10.2026', DateOrder::DayMonthYear);
        } finally {
            date_default_timezone_set($previous);
        }

        self::assertNotNull($date);
        self::assertSame('2026-10-01T00:00:00+00:00', $date->format('c'));
    }

    public function testTextCleanAndSquash(): void
    {
        self::assertSame('a b', Text::clean(self::NBSP." a b\t\n"));
        self::assertSame('ab', Text::squash(' a'.self::NBSP."\tb "));
    }
}
