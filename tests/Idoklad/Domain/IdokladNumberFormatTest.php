<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

namespace App\Tests\Idoklad\Domain;

use App\Idoklad\Domain\IdokladNumberFormat;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdokladNumberFormatTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function formats(): iterable
    {
        yield 'year with four digits' => ['RRRRNNNN', '{YYYY}{NNNN}'];
        yield 'year with two digits' => ['RRNNNNN', '{YY}{NNNNN}'];
        yield 'month' => ['RRRRMMNNN', '{YYYY}{MM}{NNN}'];
        yield 'text around' => ['FV-RRRR/NNNN-x', 'FV-{YYYY}/{NNNN}-x'];
        yield 'number only' => ['NNNNNN', '{NNNNNN}'];
        yield 'three R' => ['RRRNNNN', null];
        yield 'one R' => ['RNNNN', null];
        yield 'one M' => ['RRRRMNNN', null];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('formats')]
    public function testTheTokensOfIdokladAreTheTokensOfASeries(string $idoklad, ?string $series): void
    {
        self::assertSame($series, IdokladNumberFormat::toSeries($idoklad));
    }
}
