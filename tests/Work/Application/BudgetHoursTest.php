<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace Foresi\Tests\Work\Application;

use Foresi\Work\Application\BudgetHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BudgetHoursTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function hours(): iterable
    {
        yield 'whole' => ['8', 480];
        yield 'plus' => ['+8', 480];
        yield 'minus with a dot' => ['-2.5', -150];
        yield 'a comma' => ['1,25', 75];
        yield 'one decimal place' => ['0.1', 6];
        yield 'a hundredth to the nearest minute' => ['0.01', 1];
        yield 'two hundredths round down' => ['0.02', 1];
        yield 'hours and hundredths' => ['12.34', 740];
        yield 'spaces around' => [' 3 ', 180];
        yield 'three decimal places' => ['1.234', null];
        yield 'a word' => ['eight', null];
        yield 'empty' => ['', null];
        yield 'a colon' => ['1:30', null];
        yield 'too many digits' => ['1234567', null];
    }

    #[DataProvider('hours')]
    public function testHoursAsPeopleWriteThem(string $hours, ?int $minutes): void
    {
        self::assertSame($minutes, BudgetHours::minutes($hours));
    }
}
