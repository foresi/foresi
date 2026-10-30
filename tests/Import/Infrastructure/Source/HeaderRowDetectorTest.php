<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Import\Infrastructure\Source;

use App\Import\Infrastructure\Source\HeaderRowDetector;
use PHPUnit\Framework\TestCase;

final class HeaderRowDetectorTest extends TestCase
{
    public function testFirstRowOfAPlainTable(): void
    {
        $rows = [1 => ['Name', 'City'], 2 => ['Example', 'Praha']];

        self::assertSame(1, new HeaderRowDetector()->detect($rows));
    }

    public function testSkipsPaddedTitleRows(): void
    {
        $rows = [
            1 => ['Export', '', '', ''],
            2 => ['Generated', '', '', ''],
            3 => ['Name', 'City', 'Email', 'Phone'],
            4 => ['Example', 'Praha', 'testing@example.com', '123'],
        ];

        self::assertSame(3, new HeaderRowDetector()->detect($rows));
    }

    public function testSingleCellTitleRowsAreMeasuredAgainstTheWidestRow(): void
    {
        $rows = [
            1 => ['Export'],
            3 => ['Name', 'City', 'Email'],
        ];

        self::assertSame(3, new HeaderRowDetector()->detect($rows));
    }

    public function testSkipsBlankRowsAndKeepsSourceNumbers(): void
    {
        $rows = [1 => [], 2 => ['', ''], 5 => ['Name', 'City']];

        self::assertSame(5, new HeaderRowDetector()->detect($rows));
    }

    public function testRowWithNumericOnlyCellIsNotAHeader(): void
    {
        $rows = [
            1 => ['2026', 'Name'],
            2 => ['Name', 'City'],
        ];

        self::assertSame(2, new HeaderRowDetector()->detect($rows));
    }

    public function testDecimalAndSignedNumbersCountAsNumeric(): void
    {
        $rows = [
            1 => ['Name', '-12,5'],
            2 => ['Name', '+3.14'],
            3 => ['Name', 'City'],
        ];

        self::assertSame(3, new HeaderRowDetector()->detect($rows));
    }

    public function testSixtyPercentFilledIsEnough(): void
    {
        $rows = [1 => ['Name', 'City', 'Email', '', '']];

        self::assertSame(1, new HeaderRowDetector()->detect($rows));
    }

    public function testJustBelowSixtyPercentIsNotEnough(): void
    {
        $rows = [
            1 => ['Name', 'City', '', '', ''],
            2 => ['Name', 'City', 'Email', '', ''],
        ];

        self::assertSame(2, new HeaderRowDetector()->detect($rows));
    }

    public function testOnlyTheFirstTenRowsAreConsidered(): void
    {
        $rows = [];
        for ($row = 1; $row <= 10; ++$row) {
            $rows[$row] = ['', '', ''];
        }
        $rows[11] = ['Name', 'City', 'Email'];

        self::assertSame(1, new HeaderRowDetector()->detect($rows));
    }

    public function testFallsBackToTheFirstNonEmptyRow(): void
    {
        $rows = [1 => [], 2 => ['1', '2', '3'], 3 => ['4', '5', '6']];

        self::assertSame(2, new HeaderRowDetector()->detect($rows));
    }

    public function testNoRowsFallBackToOne(): void
    {
        self::assertSame(1, new HeaderRowDetector()->detect([]));
    }
}
