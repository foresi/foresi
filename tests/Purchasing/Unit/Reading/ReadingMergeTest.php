<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Purchasing\Unit\Reading;

use Foresi\Purchasing\Application\Reading\ReadingMerge;
use Foresi\Purchasing\Contract\Reading\ReadField;
use Foresi\Purchasing\Contract\Reading\Reading;
use Foresi\Purchasing\Contract\Reading\ReadValue;
use PHPUnit\Framework\TestCase;

final class ReadingMergeTest extends TestCase
{
    public function testFirstReaderWinsPerField(): void
    {
        $isdoc = self::reading('isdoc', [ReadField::IssueDate->value => '2026-10-01']);
        $qr = self::reading('payment_qr', [
            ReadField::IssueDate->value => '2026-10-02',
            ReadField::VariableSymbol->value => '2026100',
        ]);

        $merged = (new ReadingMerge())->merge([$isdoc, $qr]);

        $issueDate = $merged->get(ReadField::IssueDate);
        self::assertSame('2026-10-01', $issueDate?->value);
        self::assertSame('isdoc', $issueDate->source);
        $symbol = $merged->get(ReadField::VariableSymbol);
        self::assertSame('2026100', $symbol?->value);
        self::assertSame('payment_qr', $symbol->source);
    }

    public function testAmountsTravelTogether(): void
    {
        $recap = [['rate' => '21', 'baseMinor' => 10000, 'vatMinor' => 2100]];
        $a = self::reading('payment_qr', [
            ReadField::Total->value => 12100,
            ReadField::Currency->value => 'CZK',
        ]);
        $b = self::reading('isdoc', [
            ReadField::Total->value => 10000,
            ReadField::Currency->value => 'EUR',
            ReadField::VatRecap->value => $recap,
        ]);

        $merged = (new ReadingMerge())->merge([$a, $b]);

        self::assertSame(12100, $merged->get(ReadField::Total)?->value);
        self::assertSame('CZK', $merged->get(ReadField::Currency)?->value);
        self::assertSame('payment_qr', $merged->get(ReadField::Total)->source);
        self::assertNull($merged->get(ReadField::VatRecap));
    }

    public function testNoCurrencyIsTakenFromAnotherReaderWhenTheAmountsHaveNone(): void
    {
        $a = self::reading('isdoc', [ReadField::Total->value => 10000]);
        $b = self::reading('payment_qr', [
            ReadField::Total->value => 9000,
            ReadField::Currency->value => 'EUR',
        ]);
        $c = self::reading('ai', [ReadField::Currency->value => 'USD']);

        $merged = (new ReadingMerge())->merge([$a, $b, $c]);

        self::assertSame(10000, $merged->get(ReadField::Total)?->value);
        self::assertNull($merged->get(ReadField::Currency));
    }

    public function testReadingKeysFollowTheValuesOwnField(): void
    {
        $reading = new Reading(['wrong' => new ReadValue(ReadField::DueDate, '2026-10-20', 'isdoc')]);

        self::assertSame(['due_date'], array_keys($reading->values));
        self::assertSame('2026-10-20', $reading->get(ReadField::DueDate)?->value);
    }

    public function testAReadingWithoutATotalDoesNotTakePartInTheAmounts(): void
    {
        $a = self::reading('payment_qr', [ReadField::Currency->value => 'CZK']);
        $b = self::reading('isdoc', [
            ReadField::Total->value => 10000,
            ReadField::Currency->value => 'EUR',
        ]);

        $merged = (new ReadingMerge())->merge([$a, $b]);

        self::assertSame('EUR', $merged->get(ReadField::Currency)?->value);
        self::assertSame(10000, $merged->get(ReadField::Total)?->value);
    }

    public function testEmptyReadingsGiveAnEmptyReading(): void
    {
        self::assertTrue((new ReadingMerge())->merge([])->isEmpty());
        self::assertTrue((new ReadingMerge())->merge([new Reading([]), new Reading([])])->isEmpty());
    }

    public function testRoundTripToArray(): void
    {
        $recap = [['rate' => '21', 'baseMinor' => 10000, 'vatMinor' => 2100]];
        $reading = self::reading('isdoc', [
            ReadField::IssueDate->value => '2026-10-01',
            ReadField::Total->value => 12100,
            ReadField::VatRecap->value => $recap,
        ]);

        $array = $reading->toArray();
        $restored = Reading::fromArray($array);

        self::assertSame(['value' => '2026-10-01', 'source' => 'isdoc'], $array['issue_date']);
        self::assertEquals($reading, $restored);
        self::assertFalse($restored->isEmpty());
        self::assertSame($recap, $restored->get(ReadField::VatRecap)?->value);
    }

    public function testUnknownOrMalformedStoredEntriesAreSkipped(): void
    {
        $restored = Reading::fromArray([
            'issue_date' => ['value' => '2026-10-01', 'source' => 'isdoc'],
            'removed_field' => ['value' => 'x', 'source' => 'isdoc'],
            'due_date' => 'broken',
            'currency' => ['value' => null, 'source' => 'isdoc'],
        ]);

        self::assertSame(['issue_date'], array_keys($restored->values));
    }

    /**
     * @param array<string, string|int|array<mixed>> $values
     */
    private static function reading(string $source, array $values): Reading
    {
        /** @var array<value-of<ReadField>, ReadValue> $read */
        $read = [];
        foreach ($values as $key => $value) {
            $field = ReadField::from($key);
            $read[$field->value] = new ReadValue($field, $value, $source);
        }

        return new Reading($read);
    }
}
