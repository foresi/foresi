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
        yield 'braced year and number' => ['{RRRR}{NNNN}', '{YYYY}{NNNN}'];
        yield 'braced with text before' => ['Z9{RRRR}{NNNN}', 'Z9{YYYY}{NNNN}'];
        yield 'braced with text between' => ['{RRRR}1{NNNN}', '{YYYY}1{NNNN}'];
        yield 'braced short number' => ['PR{RRRR}{NNN}', 'PR{YYYY}{NNN}'];
        yield 'braced two-digit year and month' => ['{RR}{MM}-{NNNNN}', '{YY}{MM}-{NNNNN}'];
        yield 'braced text keeps R, M and N' => ['MRN{RRRR}{NNNN}', 'MRN{YYYY}{NNNN}'];
        yield 'braced three R' => ['{RRR}{NNNN}', null];
        yield 'braced mixed run' => ['{RRNN}', null];
        yield 'braced unknown token' => ['{X}{NNNN}', null];
        yield 'braced empty' => ['{}{NNNN}', null];
        yield 'unclosed brace' => ['{RRRR{NNNN}', null];
        yield 'stray closing brace' => ['RRRR}{NNNN}', null];
        yield 'only an opening brace' => ['{RRRRNNNN', null];
        yield 'only a closing brace' => ['RRRRNNNN}', null];
        yield 'braced run with a letter inside' => ['{RRRR}{NRNNN}', null];
        yield 'braced run with a line break after' => ["{RRRR}{NNNN\n}", null];
    }

    #[DataProvider('formats')]
    public function testTheTokensOfIdokladAreTheTokensOfASeries(string $idoklad, ?string $series): void
    {
        self::assertSame($series, IdokladNumberFormat::toSeries($idoklad));
    }
}
